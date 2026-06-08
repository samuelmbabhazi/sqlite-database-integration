#![cfg_attr(windows, feature(abi_vectorcall))]

use std::cell::RefCell;
use std::collections::{HashMap, HashSet};
use std::os::raw::c_char;
use std::ptr;
use std::rc::Rc;
use std::sync::Arc;

use ext_php_rs::convert::{FromZval, IntoZval, IntoZvalDyn};
use ext_php_rs::exception::{PhpException, PhpResult};
use ext_php_rs::ffi::{zend_class_entry, zend_object, zval};
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendCallable, ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ModuleEntry};
use ext_php_rs::{info_table_end, info_table_row, info_table_start};

#[rustfmt::skip]
mod compiled_packed_id_parser;
mod lexer_constants;
mod native_sqlite_connection;
mod native_sqlite_translator;
use lexer_constants as lex;
use lexer_constants::register_lexer_constants;
use native_sqlite_connection::{
    WpSqliteNativeConnection, WpSqliteNativePackedResult, WpSqliteNativeStatement,
};

const SQL_MODE_HIGH_NOT_PRECEDENCE: i64 = 1;
const SQL_MODE_PIPES_AS_CONCAT: i64 = 2;
const SQL_MODE_IGNORE_SPACE: i64 = 4;
const SQL_MODE_NO_BACKSLASH_ESCAPES: i64 = 8;
const STACK_RED_ZONE: usize = 128 * 1024;
const STACK_GROW_SIZE: usize = 8 * 1024 * 1024;

extern "C" {
    fn zend_update_property(
        scope: *mut zend_class_entry,
        object: *mut zend_object,
        name: *const c_char,
        name_length: usize,
        value: *mut zval,
    );
}

#[derive(Clone)]
struct BinaryString(Vec<u8>);

impl IntoZval for BinaryString {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, _persistent: bool) -> ext_php_rs::error::Result<()> {
        zv.set_binary(self.0);
        Ok(())
    }
}

fn php_error(message: impl ToString) -> PhpException {
    PhpException::default(message.to_string())
}

fn php_function(name: &str) -> PhpResult<ZendCallable<'_>> {
    ZendCallable::try_from_name(name).map_err(php_error)
}

struct PhpClasses {
    parser_token: &'static ClassEntry,
    mysql_token: &'static ClassEntry,
    native_parser_node: &'static ClassEntry,
}

fn php_classes() -> PhpResult<PhpClasses> {
    Ok(PhpClasses {
        parser_token: ClassEntry::try_find("WP_Parser_Token")
            .ok_or_else(|| php_error("Missing WP_Parser_Token class"))?,
        mysql_token: ClassEntry::try_find("WP_MySQL_Token")
            .ok_or_else(|| php_error("Missing WP_MySQL_Token class"))?,
        native_parser_node: ClassEntry::try_find("WP_MySQL_Native_Parser_Node")
            .ok_or_else(|| php_error("Missing WP_MySQL_Native_Parser_Node class"))?,
    })
}

fn update_object_property(
    object: &mut ZendObject,
    scope: &ClassEntry,
    name: &str,
    value: impl IntoZval,
) -> PhpResult<()> {
    let mut value = value.into_zval(false).map_err(php_error)?;
    unsafe {
        zend_update_property(
            ptr::from_ref(scope).cast_mut(),
            ptr::from_mut(object),
            name.as_ptr().cast::<c_char>(),
            name.len(),
            ptr::from_mut(&mut value),
        );
    }
    Ok(())
}

fn create_mysql_token(sql_zval: &Zval, token: TokenInfo, no_backslash: bool) -> PhpResult<Zval> {
    let classes = php_classes()?;
    create_mysql_token_with_classes(sql_zval, token, no_backslash, &classes)
}

fn create_mysql_token_with_classes(
    sql_zval: &Zval,
    token: TokenInfo,
    no_backslash: bool,
    classes: &PhpClasses,
) -> PhpResult<Zval> {
    let id = token.id;
    let start = i64::try_from(token.start).map_err(php_error)?;
    let length = i64::try_from(token.end.saturating_sub(token.start)).map_err(php_error)?;
    let mut object = classes.mysql_token.new();

    update_object_property(&mut object, classes.parser_token, "id", id)?;
    update_object_property(&mut object, classes.parser_token, "start", start)?;
    update_object_property(&mut object, classes.parser_token, "length", length)?;
    update_object_property(
        &mut object,
        classes.parser_token,
        "input",
        sql_zval.shallow_clone(),
    )?;
    update_object_property(
        &mut object,
        classes.mysql_token,
        "sql_mode_no_backslash_escapes_enabled",
        no_backslash,
    )?;

    object.into_zval(false).map_err(php_error)
}

fn sql_modes_mask(sql_modes: &[String]) -> i64 {
    let mut mask = 0;
    for sql_mode in sql_modes {
        match sql_mode.to_ascii_uppercase().as_str() {
            "HIGH_NOT_PRECEDENCE" => mask |= SQL_MODE_HIGH_NOT_PRECEDENCE,
            "PIPES_AS_CONCAT" => mask |= SQL_MODE_PIPES_AS_CONCAT,
            "IGNORE_SPACE" => mask |= SQL_MODE_IGNORE_SPACE,
            "NO_BACKSLASH_ESCAPES" => mask |= SQL_MODE_NO_BACKSLASH_ESCAPES,
            _ => {}
        }
    }
    mask
}

fn zval_to_weak_string_bytes(zv: &Zval) -> PhpResult<Vec<u8>> {
    if let Some(bytes) = zv.binary::<u8>() {
        return Ok(bytes);
    }
    if zv.is_null() || zv.is_false() {
        return Ok(Vec::new());
    }
    if zv.is_true() {
        return Ok(b"1".to_vec());
    }
    if let Some(value) = zv.long() {
        return Ok(value.to_string().into_bytes());
    }
    if let Some(value) = zv.double() {
        return Ok(value.to_string().into_bytes());
    }
    Err(php_error("Invalid value given for argument `sql`."))
}

fn byte_in(byte: u8, mask: &[u8]) -> bool {
    mask.contains(&byte)
}

fn span_while(bytes: &[u8], mut pos: usize, predicate: impl Fn(u8) -> bool) -> usize {
    while pos < bytes.len() && predicate(bytes[pos]) {
        pos += 1;
    }
    pos
}

fn span_until(bytes: &[u8], mut pos: usize, needles: &[u8]) -> usize {
    while pos < bytes.len() && !needles.contains(&bytes[pos]) {
        pos += 1;
    }
    pos
}

fn bytes_ascii_lower(bytes: &[u8]) -> String {
    let mut lower = Vec::with_capacity(bytes.len());
    lower.extend(bytes.iter().map(u8::to_ascii_lowercase));
    // The lexer only calls this for identifier slices. ASCII bytes remain
    // ASCII and non-ASCII identifier bytes have already passed the UTF-8
    // shape checks in read_identifier().
    unsafe { String::from_utf8_unchecked(lower) }
}

fn keyword_token_ascii_case_insensitive(value: &[u8]) -> Option<i64> {
    if !value.is_ascii() {
        return None;
    }

    let mut buffer = [0u8; 128];
    if value.len() <= buffer.len() {
        for (index, byte) in value.iter().enumerate() {
            buffer[index] = byte.to_ascii_uppercase();
        }
        // ASCII bytes are valid UTF-8 and the buffer slice is initialized above.
        let keyword = unsafe { std::str::from_utf8_unchecked(&buffer[..value.len()]) };
        return lex::keyword_token(keyword);
    }

    let mut upper = Vec::with_capacity(value.len());
    upper.extend(value.iter().map(u8::to_ascii_uppercase));
    // ASCII bytes are valid UTF-8.
    lex::keyword_token(unsafe { std::str::from_utf8_unchecked(&upper) })
}

#[derive(Clone, Copy)]
struct TokenInfo {
    id: i64,
    start: usize,
    end: usize,
}

#[php_class]
#[php(name = "WP_MySQL_Native_Token_Stream")]
pub struct WpMySqlNativeTokenStream {
    sql_zval: Zval,
    tokens: Vec<TokenInfo>,
    no_backslash: bool,
}

#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpMySqlNativeTokenStream {
    pub fn count(&self) -> usize {
        self.tokens.len()
    }
}

#[php_class]
#[php(name = "WP_MySQL_Native_Lexer", modifier = "register_lexer_constants")]
pub struct WpMySqlNativeLexer {
    sql: Vec<u8>,
    sql_zval: Zval,
    mysql_version: i64,
    sql_modes: i64,
    bytes_already_read: usize,
    token_starts_at: usize,
    token_type: Option<i64>,
    in_mysql_comment: bool,
}

#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpMySqlNativeLexer {
    pub fn __construct(
        sql: &Zval,
        mysql_version: Option<i64>,
        sql_modes: Option<Vec<String>>,
    ) -> PhpResult<Self> {
        let mysql_version = mysql_version.unwrap_or(80038);
        let sql_modes = sql_modes.unwrap_or_default();
        let sql = zval_to_weak_string_bytes(sql)?;
        let sql_zval = BinaryString(sql.clone())
            .into_zval(false)
            .map_err(php_error)?;

        Ok(Self::from_sql_parts(
            sql,
            sql_zval,
            mysql_version,
            sql_modes,
        ))
    }

    pub fn next_token(&mut self) -> bool {
        if self.token_type == Some(lex::EOF)
            || (self.token_type.is_none() && self.bytes_already_read > 0)
        {
            self.token_type = None;
            return false;
        }

        loop {
            self.token_starts_at = self.bytes_already_read;
            self.token_type = self.read_next_token();
            if !matches!(
                self.token_type,
                Some(
                    lex::WHITESPACE
                        | lex::COMMENT
                        | lex::MYSQL_COMMENT_START
                        | lex::MYSQL_COMMENT_END
                )
            ) {
                break;
            }
        }

        self.token_type.is_some()
    }

    pub fn get_token(&mut self) -> PhpResult<Zval> {
        match self.current_token_info() {
            Some(token) => self.create_token(token),
            None => Ok(Zval::null()),
        }
    }

    pub fn remaining_tokens(&mut self) -> PhpResult<Vec<Zval>> {
        let mut tokens = Vec::new();
        while self.next_token() {
            if let Some(token) = self.current_token_info() {
                tokens.push(self.create_token(token)?);
            }
        }
        Ok(tokens)
    }

    pub fn native_token_stream(&mut self) -> WpMySqlNativeTokenStream {
        let mut tokens = Vec::new();
        while self.next_token() {
            if let Some(token) = self.current_token_info() {
                tokens.push(token);
            }
        }

        WpMySqlNativeTokenStream {
            sql_zval: self.sql_zval.shallow_clone(),
            tokens,
            no_backslash: self.is_sql_mode_active(SQL_MODE_NO_BACKSLASH_ESCAPES),
        }
    }

    pub fn get_mysql_version(&self) -> i64 {
        self.mysql_version
    }

    pub fn is_sql_mode_active(&self, mode: i64) -> bool {
        (self.sql_modes & mode) != 0
    }

    pub fn get_token_id(token_name: String) -> Option<i64> {
        lex::token_id(&token_name)
    }

    pub fn get_token_name(token_id: i64) -> Option<String> {
        lex::token_name(token_id).map(ToOwned::to_owned)
    }

    pub fn translate_sqlite_plan(sql: &Zval) -> PhpResult<Option<Vec<String>>> {
        Ok(native_sqlite_translator::translate_sqlite_plan(
            &zval_to_weak_string_bytes(sql)?,
        ))
    }

    pub fn translate_sqlite_plan_code(sql: &Zval) -> PhpResult<i64> {
        Ok(native_sqlite_translator::translate_sqlite_plan_code(
            &zval_to_weak_string_bytes(sql)?,
        ))
    }
}

impl WpMySqlNativeLexer {
    fn from_sql_parts(
        sql: Vec<u8>,
        sql_zval: Zval,
        mysql_version: i64,
        sql_modes: Vec<String>,
    ) -> Self {
        Self::from_sql_parts_with_modes_mask(
            sql,
            sql_zval,
            mysql_version,
            sql_modes_mask(&sql_modes),
        )
    }

    fn from_sql_parts_with_modes_mask(
        sql: Vec<u8>,
        sql_zval: Zval,
        mysql_version: i64,
        sql_modes: i64,
    ) -> Self {
        Self {
            sql,
            sql_zval,
            mysql_version,
            sql_modes,
            bytes_already_read: 0,
            token_starts_at: 0,
            token_type: None,
            in_mysql_comment: false,
        }
    }

    fn from_raw_sql_zval_with_modes_mask(
        sql: &Zval,
        mysql_version: Option<i64>,
        sql_modes: i64,
    ) -> PhpResult<Self> {
        Ok(Self::from_sql_parts_with_modes_mask(
            zval_to_weak_string_bytes(sql)?,
            Zval::null(),
            mysql_version.unwrap_or(80038),
            sql_modes,
        ))
    }

    fn into_raw_token_source(mut self) -> (ParserTokenSource, Vec<i64>) {
        let no_backslash = self.is_sql_mode_active(SQL_MODE_NO_BACKSLASH_ESCAPES);
        let mut tokens = Vec::with_capacity(self.sql.len().saturating_div(4).max(1));
        while self.next_token() {
            if let Some(token) = self.current_token_info() {
                tokens.push(token);
            }
        }
        let token_ids = tokens.iter().map(|token| token.id).collect();
        (
            ParserTokenSource::RawSql {
                sql: self.sql,
                tokens,
                no_backslash,
            },
            token_ids,
        )
    }

    fn into_token_ids(mut self) -> Vec<i64> {
        let mut token_ids = Vec::with_capacity(self.sql.len().saturating_div(4).max(1));
        while self.next_token() {
            if let Some(token_id) = self.token_type {
                token_ids.push(token_id);
            }
        }
        token_ids
    }

    fn current_token_info(&self) -> Option<TokenInfo> {
        self.token_type.map(|id| TokenInfo {
            id,
            start: self.token_starts_at,
            end: self.bytes_already_read,
        })
    }

    fn create_token(&self, token: TokenInfo) -> PhpResult<Zval> {
        create_mysql_token(
            &self.sql_zval,
            token,
            self.is_sql_mode_active(SQL_MODE_NO_BACKSLASH_ESCAPES),
        )
    }

    fn read_next_token(&mut self) -> Option<i64> {
        let byte = self.byte_at(self.bytes_already_read);
        let next_byte = self.byte_at(self.bytes_already_read + 1);

        let token = match byte {
            Some(b'\'' | b'"' | b'`') => self.read_quoted_text(),
            Some(byte) if byte.is_ascii_digit() => self.read_number(),
            Some(b'.') => {
                if next_byte.is_some_and(|byte| byte.is_ascii_digit()) {
                    self.read_number()
                } else {
                    self.bytes_already_read += 1;
                    Some(lex::DOT_SYMBOL)
                }
            }
            Some(b'=') => {
                self.bytes_already_read += 1;
                Some(lex::EQUAL_OPERATOR)
            }
            Some(b':') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'=') {
                    self.bytes_already_read += 1;
                    Some(lex::ASSIGN_OPERATOR)
                } else {
                    Some(lex::COLON_SYMBOL)
                }
            }
            Some(b'<') => self.read_less_than(next_byte),
            Some(b'>') => self.read_greater_than(next_byte),
            Some(b'!') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'=') {
                    self.bytes_already_read += 1;
                    Some(lex::NOT_EQUAL_OPERATOR)
                } else {
                    Some(lex::LOGICAL_NOT_OPERATOR)
                }
            }
            Some(b'+') => {
                self.bytes_already_read += 1;
                Some(lex::PLUS_OPERATOR)
            }
            Some(b'-') => self.read_minus(next_byte),
            Some(b'*') => self.read_star(next_byte),
            Some(b'/') => self.read_slash(next_byte),
            Some(b'%') => {
                self.bytes_already_read += 1;
                Some(lex::MOD_OPERATOR)
            }
            Some(b'&') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'&') {
                    self.bytes_already_read += 1;
                    Some(lex::LOGICAL_AND_OPERATOR)
                } else {
                    Some(lex::BITWISE_AND_OPERATOR)
                }
            }
            Some(b'^') => {
                self.bytes_already_read += 1;
                Some(lex::BITWISE_XOR_OPERATOR)
            }
            Some(b'|') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'|') {
                    self.bytes_already_read += 1;
                    if self.is_sql_mode_active(SQL_MODE_PIPES_AS_CONCAT) {
                        Some(lex::CONCAT_PIPES_SYMBOL)
                    } else {
                        Some(lex::LOGICAL_OR_OPERATOR)
                    }
                } else {
                    Some(lex::BITWISE_OR_OPERATOR)
                }
            }
            Some(b'~') => {
                self.bytes_already_read += 1;
                Some(lex::BITWISE_NOT_OPERATOR)
            }
            Some(b',') => {
                self.bytes_already_read += 1;
                Some(lex::COMMA_SYMBOL)
            }
            Some(b';') => {
                self.bytes_already_read += 1;
                Some(lex::SEMICOLON_SYMBOL)
            }
            Some(b'(') => {
                self.bytes_already_read += 1;
                Some(lex::OPEN_PAR_SYMBOL)
            }
            Some(b')') => {
                self.bytes_already_read += 1;
                Some(lex::CLOSE_PAR_SYMBOL)
            }
            Some(b'{') => {
                self.bytes_already_read += 1;
                Some(lex::OPEN_CURLY_SYMBOL)
            }
            Some(b'}') => {
                self.bytes_already_read += 1;
                Some(lex::CLOSE_CURLY_SYMBOL)
            }
            Some(b'@') => self.read_at(next_byte),
            Some(b'?') => {
                self.bytes_already_read += 1;
                Some(lex::PARAM_MARKER)
            }
            Some(b'\\') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'N') {
                    self.bytes_already_read += 1;
                    Some(lex::NULL2_SYMBOL)
                } else {
                    None
                }
            }
            Some(b'#') => Some(self.read_line_comment()),
            Some(byte) if byte_in(byte, lex::WHITESPACE_MASK.as_bytes()) => {
                self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                    byte_in(byte, lex::WHITESPACE_MASK.as_bytes())
                });
                Some(lex::WHITESPACE)
            }
            Some(b'x' | b'X' | b'b' | b'B') if next_byte == Some(b'\'') => self.read_number(),
            Some(b'n' | b'N') if next_byte == Some(b'\'') => {
                self.bytes_already_read += 1;
                let token = self.read_quoted_text();
                if token == Some(lex::SINGLE_QUOTED_TEXT) {
                    Some(lex::NCHAR_TEXT)
                } else {
                    token
                }
            }
            None => Some(lex::EOF),
            Some(_) => {
                let started_at = self.bytes_already_read;
                let mut token = self.read_identifier();
                if token == Some(lex::IDENTIFIER) {
                    if started_at > 0 && self.byte_at(started_at - 1) == Some(b'.') {
                        token = Some(lex::IDENTIFIER);
                    } else if self.byte_at(started_at) == Some(b'_')
                        && lex::is_underscore_charset(&bytes_ascii_lower(
                            &self.sql[self.token_starts_at..self.bytes_already_read],
                        ))
                    {
                        token = Some(lex::UNDERSCORE_CHARSET);
                    } else {
                        token = Some(self.determine_identifier_or_keyword_type(
                            self.token_starts_at,
                            self.bytes_already_read,
                        ));
                    }
                }
                token
            }
        };

        token
    }

    fn byte_at(&self, pos: usize) -> Option<u8> {
        self.sql.get(pos).copied()
    }

    fn read_less_than(&mut self, next_byte: Option<u8>) -> Option<i64> {
        self.bytes_already_read += 1;
        if next_byte == Some(b'=') {
            self.bytes_already_read += 1;
            if self.byte_at(self.bytes_already_read) == Some(b'>') {
                self.bytes_already_read += 1;
                Some(lex::NULL_SAFE_EQUAL_OPERATOR)
            } else {
                Some(lex::LESS_OR_EQUAL_OPERATOR)
            }
        } else if next_byte == Some(b'>') {
            self.bytes_already_read += 1;
            Some(lex::NOT_EQUAL_OPERATOR)
        } else if next_byte == Some(b'<') {
            self.bytes_already_read += 1;
            Some(lex::SHIFT_LEFT_OPERATOR)
        } else {
            Some(lex::LESS_THAN_OPERATOR)
        }
    }

    fn read_greater_than(&mut self, next_byte: Option<u8>) -> Option<i64> {
        self.bytes_already_read += 1;
        if next_byte == Some(b'=') {
            self.bytes_already_read += 1;
            Some(lex::GREATER_OR_EQUAL_OPERATOR)
        } else if next_byte == Some(b'>') {
            self.bytes_already_read += 1;
            Some(lex::SHIFT_RIGHT_OPERATOR)
        } else {
            Some(lex::GREATER_THAN_OPERATOR)
        }
    }

    fn read_minus(&mut self, next_byte: Option<u8>) -> Option<i64> {
        if next_byte == Some(b'-')
            && self.bytes_already_read + 2 < self.sql.len()
            && byte_in(
                self.sql[self.bytes_already_read + 2],
                lex::WHITESPACE_MASK.as_bytes(),
            )
        {
            Some(self.read_line_comment())
        } else if next_byte == Some(b'>') {
            self.bytes_already_read += 2;
            if self.byte_at(self.bytes_already_read) == Some(b'>') {
                self.bytes_already_read += 1;
                if self.mysql_version >= 50713 {
                    Some(lex::JSON_UNQUOTED_SEPARATOR_SYMBOL)
                } else {
                    None
                }
            } else if self.mysql_version >= 50708 {
                Some(lex::JSON_SEPARATOR_SYMBOL)
            } else {
                None
            }
        } else {
            self.bytes_already_read += 1;
            Some(lex::MINUS_OPERATOR)
        }
    }

    fn read_star(&mut self, next_byte: Option<u8>) -> Option<i64> {
        self.bytes_already_read += 1;
        if next_byte == Some(b'/') && self.in_mysql_comment {
            self.bytes_already_read += 1;
            self.in_mysql_comment = false;
            Some(lex::MYSQL_COMMENT_END)
        } else {
            Some(lex::MULT_OPERATOR)
        }
    }

    fn read_slash(&mut self, next_byte: Option<u8>) -> Option<i64> {
        if next_byte == Some(b'*') {
            if self.byte_at(self.bytes_already_read + 2) == Some(b'!') {
                Some(self.read_mysql_comment())
            } else {
                self.bytes_already_read += 2;
                self.read_comment_content();
                Some(lex::COMMENT)
            }
        } else {
            self.bytes_already_read += 1;
            Some(lex::DIV_OPERATOR)
        }
    }

    fn read_at(&mut self, next_byte: Option<u8>) -> Option<i64> {
        self.bytes_already_read += 1;
        if next_byte == Some(b'@') {
            self.bytes_already_read += 1;
            return Some(lex::AT_AT_SIGN_SYMBOL);
        }

        let start = self.bytes_already_read;
        self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
            byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'.' | b'$')
        });
        if self.bytes_already_read > start {
            Some(lex::AT_TEXT_SUFFIX)
        } else {
            Some(lex::AT_SIGN_SYMBOL)
        }
    }

    fn read_identifier(&mut self) -> Option<i64> {
        let started_at = self.bytes_already_read;
        loop {
            self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'$')
            });

            let byte_1 = self.byte_at(self.bytes_already_read).unwrap_or(0);
            if !(0xC2..=0xEF).contains(&byte_1) {
                break;
            }

            let byte_2 = self.byte_at(self.bytes_already_read + 1).unwrap_or(0);
            if byte_1 <= 0xDF && (0x80..=0xBF).contains(&byte_2) {
                self.bytes_already_read += 2;
                continue;
            }

            let byte_3 = self.byte_at(self.bytes_already_read + 2).unwrap_or(0);
            if byte_1 <= 0xEF
                && (0x80..=0xBF).contains(&byte_2)
                && (0x80..=0xBF).contains(&byte_3)
                && !(byte_1 == 0xED && byte_2 >= 0xA0)
                && !(byte_1 == 0xE0 && byte_2 < 0xA0)
            {
                self.bytes_already_read += 3;
                continue;
            }

            break;
        }

        (self.bytes_already_read > started_at).then_some(lex::IDENTIFIER)
    }

    fn read_number(&mut self) -> Option<i64> {
        let byte = self.byte_at(self.bytes_already_read);
        let next_byte = self.byte_at(self.bytes_already_read + 1);
        let third_byte = self.byte_at(self.bytes_already_read + 2);
        let mut token_type;

        if (byte == Some(b'0')
            && next_byte == Some(b'x')
            && third_byte.is_some_and(|byte| byte_in(byte, lex::HEX_DIGIT_MASK.as_bytes())))
            || (matches!(byte, Some(b'x' | b'X')) && next_byte == Some(b'\''))
        {
            let is_quoted = next_byte == Some(b'\'');
            self.bytes_already_read += 2;
            self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                byte_in(byte, lex::HEX_DIGIT_MASK.as_bytes())
            });
            if is_quoted {
                if self.byte_at(self.bytes_already_read) != Some(b'\'') {
                    return None;
                }
                self.bytes_already_read += 1;
            }
            token_type = lex::HEX_NUMBER;
        } else if (byte == Some(b'0')
            && next_byte == Some(b'b')
            && matches!(third_byte, Some(b'0' | b'1')))
            || (matches!(byte, Some(b'b' | b'B')) && next_byte == Some(b'\''))
        {
            let is_quoted = next_byte == Some(b'\'');
            self.bytes_already_read += 2;
            self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                matches!(byte, b'0' | b'1')
            });
            if is_quoted {
                if self.byte_at(self.bytes_already_read) != Some(b'\'') {
                    return None;
                }
                self.bytes_already_read += 1;
            }
            token_type = lex::BIN_NUMBER;
        } else {
            self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                byte.is_ascii_digit()
            });
            token_type = lex::INT_NUMBER;

            if self.byte_at(self.bytes_already_read) == Some(b'.') {
                self.bytes_already_read += 1;
                token_type = lex::DECIMAL_NUMBER;
                self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                    byte.is_ascii_digit()
                });
            }

            let exponent = self.byte_at(self.bytes_already_read);
            let next = self.byte_at(self.bytes_already_read + 1);
            let has_exponent = matches!(exponent, Some(b'e' | b'E'))
                && (next.is_some_and(|byte| byte.is_ascii_digit())
                    || (matches!(next, Some(b'+' | b'-'))
                        && self
                            .byte_at(self.bytes_already_read + 2)
                            .is_some_and(|byte| byte.is_ascii_digit())));
            if has_exponent {
                self.bytes_already_read += 2;
                self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                    byte.is_ascii_digit()
                });
                token_type = lex::FLOAT_NUMBER;
            }
        }

        let token_bytes = &self.sql[self.token_starts_at..self.bytes_already_read];
        let possible_identifier_prefix = token_type == lex::INT_NUMBER
            || (token_bytes.first() == Some(&b'0')
                && matches!(token_bytes.get(1), Some(b'b' | b'x')));

        if possible_identifier_prefix && self.read_identifier() == Some(lex::IDENTIFIER) {
            token_type = lex::IDENTIFIER;
        }

        if token_type == lex::INT_NUMBER {
            let mut bytes = &self.sql[self.token_starts_at..self.bytes_already_read];
            if bytes.len() < 10 {
                return Some(lex::INT_NUMBER);
            }
            while bytes.first() == Some(&b'0') {
                bytes = &bytes[1..];
            }
            let len = bytes.len();
            return Some(if len < 10 {
                lex::INT_NUMBER
            } else if len == 10 {
                if bytes > b"2147483647" {
                    lex::LONG_NUMBER
                } else {
                    lex::INT_NUMBER
                }
            } else if len < 19 {
                lex::LONG_NUMBER
            } else if len == 19 {
                if bytes > b"9223372036854775807" {
                    lex::ULONGLONG_NUMBER
                } else {
                    lex::LONG_NUMBER
                }
            } else if len == 20 {
                if bytes > b"18446744073709551615" {
                    lex::DECIMAL_NUMBER
                } else {
                    lex::ULONGLONG_NUMBER
                }
            } else {
                lex::DECIMAL_NUMBER
            });
        }

        Some(token_type)
    }

    fn read_quoted_text(&mut self) -> Option<i64> {
        let quote = self.byte_at(self.bytes_already_read)?;
        self.bytes_already_read += 1;
        let no_backslash_escapes = self.is_sql_mode_active(SQL_MODE_NO_BACKSLASH_ESCAPES);
        let mut at = self.bytes_already_read;

        loop {
            at = span_until(&self.sql, at, &[quote]);

            if !no_backslash_escapes {
                let mut i = 0usize;
                while at > i && self.byte_at(at - i - 1) == Some(b'\\') {
                    i += 1;
                }
                if i % 2 == 1 {
                    at += 1;
                    if at > self.sql.len() {
                        return None;
                    }
                    continue;
                }
            }

            if self.byte_at(at) != Some(quote) {
                return None;
            }

            if self.byte_at(at + 1) == Some(quote) {
                at += 2;
                continue;
            }
            break;
        }

        self.bytes_already_read = at + 1;
        Some(match quote {
            b'`' => lex::BACK_TICK_QUOTED_ID,
            b'"' => lex::DOUBLE_QUOTED_TEXT,
            _ => lex::SINGLE_QUOTED_TEXT,
        })
    }

    fn read_line_comment(&mut self) -> i64 {
        self.bytes_already_read = span_until(&self.sql, self.bytes_already_read, b"\r\n");
        lex::COMMENT
    }

    fn read_mysql_comment(&mut self) -> i64 {
        self.bytes_already_read += 3;
        let digit_start = self.bytes_already_read;
        let digit_end =
            span_while(&self.sql, digit_start, |byte| byte.is_ascii_digit()).min(digit_start + 5);
        let digit_count = digit_end - digit_start;
        let is_version_comment = digit_count == 5;
        let version = if is_version_comment {
            std::str::from_utf8(&self.sql[digit_start..digit_end])
                .ok()
                .and_then(|value| value.parse::<i64>().ok())
                .unwrap_or(0)
        } else {
            0
        };

        if self.mysql_version < version {
            self.read_comment_content();
            lex::COMMENT
        } else {
            self.bytes_already_read += digit_count;
            self.in_mysql_comment = true;
            lex::MYSQL_COMMENT_START
        }
    }

    fn read_comment_content(&mut self) {
        loop {
            self.bytes_already_read = span_until(&self.sql, self.bytes_already_read, b"*");
            self.bytes_already_read += 1;
            match self.byte_at(self.bytes_already_read) {
                None => break,
                Some(b'/') => {
                    self.bytes_already_read += 1;
                    break;
                }
                _ => {}
            }
        }
    }

    fn determine_identifier_or_keyword_type(&mut self, start: usize, end: usize) -> i64 {
        let value = &self.sql[start..end];
        let keyword = if value.iter().any(u8::is_ascii_lowercase) {
            match keyword_token_ascii_case_insensitive(value) {
                Some(token_type) => return self.determine_keyword_type(token_type),
                None => return lex::IDENTIFIER,
            }
        } else {
            match std::str::from_utf8(value) {
                Ok(value) => value,
                Err(_) => return lex::IDENTIFIER,
            }
        };

        let token_type = match lex::keyword_token(keyword) {
            Some(token_type) => token_type,
            None => return lex::IDENTIFIER,
        };

        self.determine_keyword_type(token_type)
    }

    fn determine_keyword_type(&mut self, mut token_type: i64) -> i64 {
        if let Some(version) = lex::version_rule(token_type) {
            if self.mysql_version < version || -version >= self.mysql_version {
                return lex::IDENTIFIER;
            }
        }

        if token_type == lex::MAX_STATEMENT_TIME_SYMBOL
            && !(self.mysql_version >= 50704 && self.mysql_version < 50708)
        {
            return lex::IDENTIFIER;
        }
        if token_type == lex::NONBLOCKING_SYMBOL
            && !(self.mysql_version >= 50700 && self.mysql_version < 50706)
        {
            return lex::IDENTIFIER;
        }
        if token_type == lex::REMOTE_SYMBOL
            && (self.mysql_version >= 80003 && self.mysql_version < 80014)
        {
            return lex::IDENTIFIER;
        }

        if lex::is_function_token(token_type) {
            if self.is_sql_mode_active(SQL_MODE_IGNORE_SPACE) {
                self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                    byte_in(byte, lex::WHITESPACE_MASK.as_bytes())
                });
            }
            if self.byte_at(self.bytes_already_read) != Some(b'(') {
                return lex::IDENTIFIER;
            }
        }

        if token_type == lex::NOT_SYMBOL && self.is_sql_mode_active(SQL_MODE_HIGH_NOT_PRECEDENCE) {
            token_type = lex::NOT2_SYMBOL;
        }

        lex::token_synonym(token_type).unwrap_or(token_type)
    }
}

struct Grammar {
    highest_terminal_id: i64,
    rules: Vec<Option<Rule>>,
    query_rule_id: i64,
    select_statement_rule_id: Option<i64>,
}

struct Rule {
    branches: Vec<Vec<i64>>,
    /// FIRST set: token ids that can start a match for this rule.
    /// `None` means the rule has no FIRST entry at all (cannot match the
    /// non-empty case); see `nullable` for the empty case.
    first_set: Option<FirstSet>,
    /// At least one branch is nullable (matches empty input).
    nullable: bool,
    rule_name: String,
    is_fragment: bool,
}

enum FirstSet {
    One(i64),
    Two(i64, i64),
    Sorted(Vec<i64>),
    Bits(Box<[u64]>),
}

impl FirstSet {
    fn from_ids(mut values: Vec<i64>) -> Option<Self> {
        values.sort_unstable();
        values.dedup();

        match values.len() {
            0 => None,
            1 => Some(Self::One(values[0])),
            2 => Some(Self::Two(values[0], values[1])),
            3..=31 => Some(Self::Sorted(values)),
            _ => {
                if values.iter().any(|token_id| *token_id < 0) {
                    return Some(Self::Sorted(values));
                }
                let max = values.iter().copied().max().unwrap_or(0);
                let Ok(max) = usize::try_from(max) else {
                    return Some(Self::Sorted(values));
                };
                let mut bits = vec![0; max / 64 + 1];
                for token_id in values {
                    let Ok(token_id) = usize::try_from(token_id) else {
                        continue;
                    };
                    bits[token_id / 64] |= 1 << (token_id % 64);
                }
                Some(Self::Bits(bits.into_boxed_slice()))
            }
        }
    }

    fn contains(&self, token_id: i64) -> bool {
        match self {
            Self::One(expected) => *expected == token_id,
            Self::Two(first, second) => *first == token_id || *second == token_id,
            Self::Sorted(values) => values.binary_search(&token_id).is_ok(),
            Self::Bits(bits) => {
                let Ok(token_id) = usize::try_from(token_id) else {
                    return false;
                };
                bits.get(token_id / 64)
                    .map(|word| (word & (1 << (token_id % 64))) != 0)
                    .unwrap_or(false)
            }
        }
    }
}

impl Grammar {
    fn rule(&self, rule_id: i64) -> Option<&Rule> {
        usize::try_from(rule_id)
            .ok()
            .and_then(|index| self.rules.get(index))
            .and_then(Option::as_ref)
    }
}

#[php_class]
#[php(name = "WP_MySQL_Native_Grammar")]
pub struct WpMySqlNativeGrammar {
    grammar: Arc<Grammar>,
}

enum ParserTokenSource {
    Php(Vec<Zval>),
    Native {
        sql_zval: Zval,
        tokens: Vec<TokenInfo>,
        no_backslash: bool,
    },
    RawSql {
        sql: Vec<u8>,
        tokens: Vec<TokenInfo>,
        no_backslash: bool,
    },
}

impl ParserTokenSource {
    fn sql_bytes(&self) -> Option<Vec<u8>> {
        match self {
            Self::Native { sql_zval, .. } => sql_zval.binary::<u8>(),
            Self::RawSql { sql, .. } => Some(sql.clone()),
            Self::Php(_) => None,
        }
    }

    fn create_php_token_with_classes(&self, index: usize, classes: &PhpClasses) -> PhpResult<Zval> {
        match self {
            Self::Php(tokens) => tokens
                .get(index)
                .map(Zval::shallow_clone)
                .ok_or_else(|| php_error("Parser token index is out of range")),
            Self::Native {
                sql_zval,
                tokens,
                no_backslash,
            } => {
                let token = tokens
                    .get(index)
                    .copied()
                    .ok_or_else(|| php_error("Parser token index is out of range"))?;
                create_mysql_token_with_classes(sql_zval, token, *no_backslash, classes)
            }
            Self::RawSql {
                sql,
                tokens,
                no_backslash,
            } => {
                let token = tokens
                    .get(index)
                    .copied()
                    .ok_or_else(|| php_error("Parser token index is out of range"))?;
                let sql_zval = BinaryString(sql.clone())
                    .into_zval(false)
                    .map_err(php_error)?;
                create_mysql_token_with_classes(&sql_zval, token, *no_backslash, classes)
            }
        }
    }

    fn token_info(&self, index: usize) -> PhpResult<TokenInfo> {
        match self {
            Self::Php(tokens) => {
                let token = tokens
                    .get(index)
                    .ok_or_else(|| php_error("Parser token index is out of range"))?;
                let token_object = token
                    .object()
                    .ok_or_else(|| php_error("Parser token must be an object"))?;
                let id = token_object.get_property::<i64>("id").map_err(php_error)?;
                let start = token_object
                    .get_property::<i64>("start")
                    .map_err(php_error)?;
                let length = token_object
                    .get_property::<i64>("length")
                    .map_err(php_error)?;
                let start = usize::try_from(start).map_err(php_error)?;
                let length = usize::try_from(length).map_err(php_error)?;

                Ok(TokenInfo {
                    id,
                    start,
                    end: start.saturating_add(length),
                })
            }
            Self::Native { tokens, .. } => tokens
                .get(index)
                .copied()
                .ok_or_else(|| php_error("Parser token index is out of range")),
            Self::RawSql { tokens, .. } => tokens
                .get(index)
                .copied()
                .ok_or_else(|| php_error("Parser token index is out of range")),
        }
    }
}

#[derive(Clone, Copy)]
enum NativeAstChild {
    Node(usize),
    Token(usize),
}

#[derive(Clone, Copy)]
enum NativeAstRoot {
    No,
    Empty,
    Node(usize),
    Token(usize),
}

enum NativeParseMatch {
    No,
    Empty,
    Node(usize),
    Token(usize),
    Fragment(Vec<NativeAstChild>),
}

#[derive(Clone, Copy)]
enum NativeAstRowFormat {
    Id,
    PackedId,
    Scalar,
    PackedScalar,
}

enum NativeDirectParseMatch {
    No,
    Empty,
    Match,
}

#[derive(Clone, Copy)]
enum NativeAstStatsFormat {
    PackedId,
    PackedScalar { consume_token_bytes: bool },
}

struct NativeAstRows {
    rows: Vec<i64>,
    format: NativeAstRowFormat,
}

struct NativeAstStats {
    descendants: i64,
    checksum: i64,
    format: NativeAstStatsFormat,
    sql_bytes: Option<Vec<u8>>,
}

impl NativeAstRowFormat {
    fn estimated_elements_per_token(self) -> usize {
        match self {
            Self::PackedId => 8,
            Self::Id | Self::PackedScalar => 16,
            Self::Scalar => 32,
        }
    }
}

impl NativeAstRows {
    fn with_token_capacity(token_count: usize, format: NativeAstRowFormat) -> Self {
        Self {
            rows: Vec::with_capacity(
                token_count.saturating_mul(format.estimated_elements_per_token()),
            ),
            format,
        }
    }

    fn len(&self) -> usize {
        self.rows.len()
    }

    fn truncate(&mut self, len: usize) {
        self.rows.truncate(len);
    }

    fn push_node(&mut self, rule_id: i64) {
        match self.format {
            NativeAstRowFormat::Id => {
                self.rows.push(0);
                self.rows.push(rule_id);
            }
            NativeAstRowFormat::PackedId => {
                self.rows.push(native_ast_pack_kind_id(0, rule_id));
            }
            NativeAstRowFormat::Scalar => {
                self.rows.push(0);
                self.rows.push(rule_id);
                self.rows.push(-1);
                self.rows.push(0);
            }
            NativeAstRowFormat::PackedScalar => {
                self.rows.push(native_ast_pack_kind_id(0, rule_id));
                self.rows.push(-1);
            }
        }
    }

    fn push_token(&mut self, token: TokenInfo) {
        match self.format {
            NativeAstRowFormat::Id => {
                self.push_token_id(token.id);
            }
            NativeAstRowFormat::PackedId => {
                self.push_token_id(token.id);
            }
            NativeAstRowFormat::Scalar => {
                self.rows.push(1);
                self.rows.push(token.id);
                self.rows.push(token.start as i64);
                self.rows.push(token.end.saturating_sub(token.start) as i64);
            }
            NativeAstRowFormat::PackedScalar => {
                self.rows.push(native_ast_pack_kind_id(1, token.id));
                self.rows.push(native_ast_pack_span(
                    token.start,
                    token.end.saturating_sub(token.start),
                ));
            }
        }
    }

    fn push_token_id(&mut self, token_id: i64) {
        match self.format {
            NativeAstRowFormat::Id => {
                self.rows.push(1);
                self.rows.push(token_id);
            }
            NativeAstRowFormat::PackedId => {
                self.rows.push(native_ast_pack_kind_id(1, token_id));
            }
            NativeAstRowFormat::Scalar | NativeAstRowFormat::PackedScalar => {}
        }
    }

    fn into_vec(self) -> Vec<i64> {
        self.rows
    }
}

impl NativeAstStats {
    fn new(format: NativeAstStatsFormat, sql_bytes: Option<Vec<u8>>) -> Self {
        Self {
            descendants: 0,
            checksum: 0,
            format,
            sql_bytes,
        }
    }

    fn state(&self) -> (i64, i64) {
        (self.descendants, self.checksum)
    }

    fn restore(&mut self, state: (i64, i64)) {
        self.descendants = state.0;
        self.checksum = state.1;
    }

    fn push_node(&mut self, rule_id: i64) {
        self.descendants += 1;
        match self.format {
            NativeAstStatsFormat::PackedId => {
                self.checksum += native_ast_pack_kind_id(0, rule_id);
            }
            NativeAstStatsFormat::PackedScalar { .. } => {
                self.checksum += native_ast_pack_kind_id(0, rule_id) - 1;
            }
        }
    }

    fn push_token_id(&mut self, token_id: i64) {
        self.descendants += 1;
        self.checksum += native_ast_pack_kind_id(1, token_id);
    }

    fn push_token(&mut self, token: TokenInfo) {
        self.descendants += 1;
        self.checksum += native_ast_pack_kind_id(1, token.id)
            + token.start as i64
            + token.end.saturating_sub(token.start) as i64;
        if matches!(
            self.format,
            NativeAstStatsFormat::PackedScalar {
                consume_token_bytes: true
            }
        ) {
            if let Some(sql_bytes) = self.sql_bytes.as_ref() {
                let end = token.end.min(sql_bytes.len());
                let start = token.start.min(end);
                self.checksum += checksum_bytes(&sql_bytes[start..end]);
            }
        }
    }

    fn into_vec(self) -> Vec<i64> {
        vec![self.descendants, self.checksum]
    }
}

fn checksum_bytes(bytes: &[u8]) -> i64 {
    bytes.len() as i64 + bytes.iter().map(|byte| *byte as i64).sum::<i64>()
}

// Raw-SQL packed-id stats do not need token spans, token objects, or a token
// source. Keep this path separate so the fastest benchmark only lexes token ids.
fn parse_recursive_packed_id_stats(
    grammar: &Grammar,
    token_ids: &[i64],
    position: &mut usize,
    rule_id: i64,
    stats: &mut NativeAstStats,
    skip_current_node: bool,
) -> NativeDirectParseMatch {
    if rule_id <= grammar.highest_terminal_id {
        if *position >= token_ids.len() {
            return NativeDirectParseMatch::No;
        }
        if rule_id == 0 {
            return NativeDirectParseMatch::Empty;
        }
        if token_ids[*position] == rule_id {
            *position += 1;
            stats.push_token_id(rule_id);
            return NativeDirectParseMatch::Match;
        }
        return NativeDirectParseMatch::No;
    }

    let Some(rule) = grammar.rule(rule_id) else {
        return NativeDirectParseMatch::No;
    };
    if rule.branches.is_empty() {
        return NativeDirectParseMatch::No;
    }

    if let Some(first_set) = rule.first_set.as_ref() {
        let token_id = token_ids.get(*position).copied().unwrap_or(0);
        if !first_set.contains(token_id) && !rule.nullable {
            return NativeDirectParseMatch::No;
        }
    } else if !rule.nullable {
        return NativeDirectParseMatch::No;
    }

    let starting_position = *position;
    let starting_stats = stats.state();

    for branch in &rule.branches {
        *position = starting_position;
        stats.restore(starting_stats);
        let emit_node = !skip_current_node && !rule.is_fragment;
        if emit_node {
            stats.push_node(rule_id);
        }
        let mut branch_matches = true;
        let mut has_children = false;

        for &subrule_id in branch {
            let child_starting_descendants = stats.descendants;
            match parse_recursive_packed_id_stats(
                grammar, token_ids, position, subrule_id, stats, false,
            ) {
                NativeDirectParseMatch::No => {
                    branch_matches = false;
                    break;
                }
                NativeDirectParseMatch::Empty => {}
                NativeDirectParseMatch::Match => {
                    if stats.descendants != child_starting_descendants {
                        has_children = true;
                    }
                }
            }
        }

        if branch_matches
            && grammar.select_statement_rule_id == Some(rule_id)
            && token_ids
                .get(*position)
                .is_some_and(|token_id| *token_id == lex::INTO_SYMBOL)
        {
            branch_matches = false;
        }

        if branch_matches {
            if has_children {
                return NativeDirectParseMatch::Match;
            }
            stats.restore(starting_stats);
            return NativeDirectParseMatch::Empty;
        }
    }

    *position = starting_position;
    stats.restore(starting_stats);
    NativeDirectParseMatch::No
}

struct NativeAstNode {
    rule_id: i64,
    children: Vec<NativeAstChild>,
    first_token: Option<usize>,
    last_token: Option<usize>,
    descendant_count: usize,
}

struct NativeAstArena {
    grammar: Arc<Grammar>,
    token_source: Arc<ParserTokenSource>,
    nodes: Vec<NativeAstNode>,
    root: NativeAstRoot,
}

struct NativeAstState {
    arena: Arc<NativeAstArena>,
    /// Per-AST identity map: node arena index → live PHP wrapper pointer.
    ///
    /// `WP_Parser_Node` callers expect stable child identity (mutate a child
    /// once, walk past, walk back, the mutation is still there). Each
    /// accessor in this extension constructs a fresh wrapper unless we
    /// intern it here. Arena node indexes are dense, so a vector avoids
    /// hashing in hot AST-walk paths. The vector is allocated lazily: parser
    /// and scalar-row paths only create the root wrapper and never need child
    /// identity lookups. The cache intentionally stores raw wrapper pointers,
    /// not strong PHP references, so Rust can preserve identity without
    /// pinning wrappers after PHP drops them.
    node_cache: RefCell<Option<Vec<Option<usize>>>>,
}

struct NativeAstWrapperEntry {
    ast: Rc<NativeAstState>,
    node_index: usize,
    /// Materialized wrappers still participate in identity lookups but no
    /// longer delegate reads through the native AST bridge.
    is_materialized: bool,
}

thread_local! {
    static NATIVE_AST_WRAPPERS: RefCell<HashMap<usize, NativeAstWrapperEntry>> = RefCell::new(HashMap::new());
}

impl NativeAstArena {
    fn new(grammar: Arc<Grammar>, token_source: Arc<ParserTokenSource>) -> Self {
        Self {
            grammar,
            token_source,
            nodes: Vec::new(),
            root: NativeAstRoot::No,
        }
    }

    fn push_node(&mut self, rule_id: i64, children: Vec<NativeAstChild>) -> usize {
        let index = self.nodes.len();
        let mut first_token = None;
        let mut last_token = None;
        let mut descendant_count = 0;
        for child in &children {
            match child {
                NativeAstChild::Node(child_index) => {
                    if let Some(node) = self.nodes.get(*child_index) {
                        descendant_count += 1 + node.descendant_count;
                        if first_token.is_none() {
                            first_token = node.first_token;
                        }
                        if node.last_token.is_some() {
                            last_token = node.last_token;
                        }
                    }
                }
                NativeAstChild::Token(token_index) => {
                    if first_token.is_none() {
                        first_token = Some(*token_index);
                    }
                    last_token = Some(*token_index);
                    descendant_count += 1;
                }
            }
        }

        self.nodes.push(NativeAstNode {
            rule_id,
            children,
            first_token,
            last_token,
            descendant_count,
        });
        index
    }

    fn node(&self, index: usize) -> PhpResult<&NativeAstNode> {
        self.nodes
            .get(index)
            .ok_or_else(|| php_error("Native AST node index is out of range"))
    }

    fn child_node_matches(&self, child: NativeAstChild, rule_name: Option<&str>) -> bool {
        let NativeAstChild::Node(index) = child else {
            return false;
        };
        let Ok(node) = self.node(index) else {
            return false;
        };
        match rule_name {
            Some(expected) => self
                .grammar
                .rule(node.rule_id)
                .map(|rule| rule.rule_name == expected)
                .unwrap_or(false),
            None => true,
        }
    }

    fn child_token_matches(&self, child: NativeAstChild, token_id: Option<i64>) -> bool {
        let NativeAstChild::Token(index) = child else {
            return false;
        };
        match token_id {
            Some(expected) => self
                .token_source
                .token_info(index)
                .map(|token| token.id == expected)
                .unwrap_or(false),
            None => true,
        }
    }

    fn descendant_stack(&self, index: usize) -> PhpResult<Vec<NativeAstChild>> {
        let node = self.node(index)?;
        let mut stack = Vec::with_capacity(node.children.len());
        stack.extend(node.children.iter().rev().copied());
        Ok(stack)
    }
}

fn native_ast_child_handle(child: NativeAstChild) -> PhpResult<i64> {
    let (index, is_token) = match child {
        NativeAstChild::Node(index) => (index, 0_i64),
        NativeAstChild::Token(index) => (index, 1_i64),
    };
    let index = i64::try_from(index).map_err(php_error)?;
    index
        .checked_mul(2)
        .and_then(|value| value.checked_add(is_token))
        .ok_or_else(|| php_error("Native AST handle is out of range"))
}

fn native_ast_child_from_handle(handle: i64) -> PhpResult<NativeAstChild> {
    if handle < 0 {
        return Err(php_error("Native AST handle must be non-negative"));
    }
    let index = usize::try_from(handle / 2).map_err(php_error)?;
    if handle % 2 == 0 {
        Ok(NativeAstChild::Node(index))
    } else {
        Ok(NativeAstChild::Token(index))
    }
}

fn native_ast_pack_kind_id(kind: i64, id: i64) -> i64 {
    id * 2 + kind
}

fn native_ast_pack_span(start: usize, length: usize) -> i64 {
    (start as i64) * (1_i64 << 32) + length as i64
}

fn native_ast_node_index_from_handle(handle: i64) -> PhpResult<usize> {
    match native_ast_child_from_handle(handle)? {
        NativeAstChild::Node(index) => Ok(index),
        NativeAstChild::Token(_) => Err(php_error("Native AST handle does not refer to a node")),
    }
}

fn native_ast_wrapper_key(wrapper_zval: &Zval) -> PhpResult<usize> {
    let object = wrapper_zval
        .object()
        .ok_or_else(|| php_error("Missing native AST wrapper"))?;
    Ok(ptr::from_ref(object) as usize)
}

fn native_ast_from_wrapper(wrapper_zval: &Zval) -> PhpResult<(Rc<NativeAstState>, usize)> {
    let key = native_ast_wrapper_key(wrapper_zval)?;
    NATIVE_AST_WRAPPERS
        .with(|wrappers| {
            wrappers.borrow().get(&key).and_then(|entry| {
                (!entry.is_materialized).then(|| (Rc::clone(&entry.ast), entry.node_index))
            })
        })
        .ok_or_else(|| php_error("Missing native AST handle"))
}

fn register_native_ast_wrapper(
    object: &ZendObject,
    ast: &Rc<NativeAstState>,
    node_index: usize,
) -> usize {
    let key = ptr::from_ref(object) as usize;
    NATIVE_AST_WRAPPERS.with(|wrappers| {
        wrappers.borrow_mut().insert(
            key,
            NativeAstWrapperEntry {
                ast: Rc::clone(ast),
                node_index,
                is_materialized: false,
            },
        );
    });
    if let Some(cache) = ast.node_cache.borrow_mut().as_mut() {
        if let Some(slot) = cache.get_mut(node_index) {
            *slot = Some(key);
        }
    }
    key
}

fn mark_native_ast_wrapper_materialized_key(key: usize) {
    NATIVE_AST_WRAPPERS.with(|wrappers| {
        if let Some(entry) = wrappers.borrow_mut().get_mut(&key) {
            entry.is_materialized = true;
        }
    });
}

fn release_native_ast_wrapper_key(key: usize) {
    let entry = NATIVE_AST_WRAPPERS.with(|wrappers| wrappers.borrow_mut().remove(&key));
    if let Some(entry) = entry {
        let mut cache = entry.ast.node_cache.borrow_mut();
        if let Some(cache) = cache.as_mut() {
            if let Some(slot) = cache.get_mut(entry.node_index) {
                if *slot == Some(key) {
                    *slot = None;
                }
            }
        }
    }
}

fn native_ast_wrapper_matches(key: usize, ast: &Rc<NativeAstState>, node_index: usize) -> bool {
    NATIVE_AST_WRAPPERS.with(|wrappers| {
        wrappers
            .borrow()
            .get(&key)
            .is_some_and(|entry| Rc::ptr_eq(&entry.ast, ast) && entry.node_index == node_index)
    })
}

/// Build a Zval that references an existing PHP object.
///
/// Used on cache hits to hand a live wrapper back to PHP without allocating a
/// new object. `Zval::set_object()` bumps the object refcount for the returned
/// zval; the Rust cache only stores the pointer and does not own a reference.
unsafe fn zval_from_cached_object(key: usize) -> Zval {
    let obj = &mut *(key as *mut ZendObject);
    let mut zv = Zval::new();
    zv.set_object(obj);
    zv
}

impl NativeAstState {
    fn new(arena: Arc<NativeAstArena>) -> Rc<Self> {
        Rc::new(Self {
            node_cache: RefCell::new(None),
            arena,
        })
    }

    fn create_php_ast(self: &Rc<Self>) -> PhpResult<Zval> {
        let classes = php_classes()?;
        self.create_php_ast_with_classes(&classes)
    }

    fn create_php_ast_with_classes(self: &Rc<Self>, classes: &PhpClasses) -> PhpResult<Zval> {
        match self.arena.root {
            NativeAstRoot::No => Ok(Zval::null()),
            NativeAstRoot::Empty => {
                let mut zval = Zval::new();
                zval.set_bool(true);
                Ok(zval)
            }
            NativeAstRoot::Node(index) => self.create_php_node_with_classes(index, classes),
            NativeAstRoot::Token(index) => self
                .arena
                .token_source
                .create_php_token_with_classes(index, classes),
        }
    }

    /// Resolve a child slot to a Zval, going through the per-AST identity
    /// cache for nodes. Tokens are not yet cached — they have no public
    /// mutators and no caller in this repo relies on token identity.
    fn cached_child_zval(
        self: &Rc<Self>,
        child: NativeAstChild,
        classes: &PhpClasses,
    ) -> PhpResult<Zval> {
        match child {
            NativeAstChild::Node(index) => self.cached_node_zval(index, classes),
            NativeAstChild::Token(index) => self
                .arena
                .token_source
                .create_php_token_with_classes(index, classes),
        }
    }

    fn cached_node_zval(self: &Rc<Self>, index: usize, classes: &PhpClasses) -> PhpResult<Zval> {
        let cached_key = {
            let cache = self.node_cache.borrow();
            cache
                .as_ref()
                .and_then(|cache| cache.get(index))
                .and_then(|entry| *entry)
        };
        if let Some(key) = cached_key {
            if native_ast_wrapper_matches(key, self, index) {
                return Ok(unsafe { zval_from_cached_object(key) });
            }
            if let Some(cache) = self.node_cache.borrow_mut().as_mut() {
                if let Some(slot) = cache.get_mut(index) {
                    *slot = None;
                }
            }
        }

        if self.node_cache.borrow().is_none() {
            *self.node_cache.borrow_mut() = Some(vec![None; self.arena.nodes.len()]);
        }
        self.create_php_node_with_classes(index, classes)
    }

    fn create_php_node_with_classes(
        self: &Rc<Self>,
        index: usize,
        classes: &PhpClasses,
    ) -> PhpResult<Zval> {
        let node = self.arena.node(index)?;
        let mut object = classes.native_parser_node.new();
        let rule_name = self
            .arena
            .grammar
            .rule(node.rule_id)
            .map(|rule| rule.rule_name.as_str())
            .unwrap_or_default();

        update_object_property(
            &mut object,
            classes.native_parser_node,
            "rule_id",
            node.rule_id,
        )?;
        update_object_property(
            &mut object,
            classes.native_parser_node,
            "rule_name",
            rule_name.to_owned(),
        )?;

        register_native_ast_wrapper(object.as_ref(), self, index);
        object.into_zval(false).map_err(php_error)
    }
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_release_wrapper(wrapper_zval: &Zval) -> PhpResult<()> {
    let key = native_ast_wrapper_key(wrapper_zval)?;
    release_native_ast_wrapper_key(key);
    Ok(())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_materialize_wrapper(wrapper_zval: &Zval) -> PhpResult<()> {
    let key = native_ast_wrapper_key(wrapper_zval)?;
    mark_native_ast_wrapper_materialized_key(key);
    Ok(())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_has_child(wrapper_zval: &Zval) -> PhpResult<bool> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    Ok(!ast.arena.node(node_index)?.children.is_empty())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_has_child_node(
    wrapper_zval: &Zval,
    rule_name: Option<String>,
) -> PhpResult<bool> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    Ok(ast
        .arena
        .node(node_index)?
        .children
        .iter()
        .copied()
        .any(|child| ast.arena.child_node_matches(child, rule_name.as_deref())))
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_has_child_token(
    wrapper_zval: &Zval,
    token_id: Option<i64>,
) -> PhpResult<bool> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    Ok(ast
        .arena
        .node(node_index)?
        .children
        .iter()
        .copied()
        .any(|child| ast.arena.child_token_matches(child, token_id)))
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_child(wrapper_zval: &Zval) -> PhpResult<Zval> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    let Some(child) = ast.arena.node(node_index)?.children.first().copied() else {
        return Ok(Zval::null());
    };
    ast.cached_child_zval(child, &classes)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_child_node(
    wrapper_zval: &Zval,
    rule_name: Option<String>,
) -> PhpResult<Zval> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    for child in &ast.arena.node(node_index)?.children {
        if ast.arena.child_node_matches(*child, rule_name.as_deref()) {
            return ast.cached_child_zval(*child, &classes);
        }
    }
    Ok(Zval::null())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_child_token(
    wrapper_zval: &Zval,
    token_id: Option<i64>,
) -> PhpResult<Zval> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    for child in &ast.arena.node(node_index)?.children {
        if ast.arena.child_token_matches(*child, token_id) {
            return ast.cached_child_zval(*child, &classes);
        }
    }
    Ok(Zval::null())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_descendant_node(
    wrapper_zval: &Zval,
    rule_name: Option<String>,
) -> PhpResult<Zval> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    let mut stack = ast.arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        if ast.arena.child_node_matches(child, rule_name.as_deref()) {
            return ast.cached_child_zval(child, &classes);
        }
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(Zval::null())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_descendant_token(
    wrapper_zval: &Zval,
    token_id: Option<i64>,
) -> PhpResult<Zval> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    let mut stack = ast.arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        if ast.arena.child_token_matches(child, token_id) {
            return ast.cached_child_zval(child, &classes);
        }
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(Zval::null())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_children(wrapper_zval: &Zval) -> PhpResult<Vec<Zval>> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    ast.arena
        .node(node_index)?
        .children
        .iter()
        .copied()
        .map(|child| ast.cached_child_zval(child, &classes))
        .collect()
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_child_nodes(
    wrapper_zval: &Zval,
    rule_name: Option<String>,
) -> PhpResult<Vec<Zval>> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    ast.arena
        .node(node_index)?
        .children
        .iter()
        .copied()
        .filter(|child| ast.arena.child_node_matches(*child, rule_name.as_deref()))
        .map(|child| ast.cached_child_zval(child, &classes))
        .collect()
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_child_tokens(
    wrapper_zval: &Zval,
    token_id: Option<i64>,
) -> PhpResult<Vec<Zval>> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    ast.arena
        .node(node_index)?
        .children
        .iter()
        .copied()
        .filter(|child| ast.arena.child_token_matches(*child, token_id))
        .map(|child| ast.cached_child_zval(child, &classes))
        .collect()
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_descendants(wrapper_zval: &Zval) -> PhpResult<Vec<Zval>> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    let root = ast.arena.node(node_index)?;
    let mut descendants = Vec::with_capacity(root.descendant_count);
    let mut stack = ast.arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        descendants.push(ast.cached_child_zval(child, &classes)?);
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(descendants)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_descendant_nodes(
    wrapper_zval: &Zval,
    rule_name: Option<String>,
) -> PhpResult<Vec<Zval>> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    let mut descendants = Vec::new();
    let mut stack = ast.arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        if ast.arena.child_node_matches(child, rule_name.as_deref()) {
            descendants.push(ast.cached_child_zval(child, &classes)?);
        }
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(descendants)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_descendant_tokens(
    wrapper_zval: &Zval,
    token_id: Option<i64>,
) -> PhpResult<Vec<Zval>> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let classes = php_classes()?;
    let mut descendants = Vec::new();
    let mut stack = ast.arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        if ast.arena.child_token_matches(child, token_id) {
            descendants.push(ast.cached_child_zval(child, &classes)?);
        }
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(descendants)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_native_handle(wrapper_zval: &Zval) -> PhpResult<i64> {
    let (_ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    native_ast_child_handle(NativeAstChild::Node(node_index))
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_native_child_handles(
    wrapper_zval: &Zval,
    handle: i64,
) -> PhpResult<Vec<i64>> {
    let (ast, _node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let node_index = native_ast_node_index_from_handle(handle)?;
    ast.arena
        .node(node_index)?
        .children
        .iter()
        .copied()
        .map(native_ast_child_handle)
        .collect()
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_native_descendant_handles(
    wrapper_zval: &Zval,
    handle: i64,
) -> PhpResult<Vec<i64>> {
    let (ast, _node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let node_index = native_ast_node_index_from_handle(handle)?;
    let node = ast.arena.node(node_index)?;
    let mut descendants = Vec::with_capacity(node.descendant_count);
    let mut stack = ast.arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        descendants.push(native_ast_child_handle(child)?);
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(descendants)
}

fn native_ast_descendant_id_rows(arena: &NativeAstArena, node_index: usize) -> PhpResult<Vec<i64>> {
    let node = arena.node(node_index)?;
    let mut rows = Vec::with_capacity(node.descendant_count.saturating_mul(2));
    let mut stack = arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        match child {
            NativeAstChild::Node(index) => {
                let node = arena.node(index)?;
                rows.push(0);
                rows.push(node.rule_id);
                for child in node.children.iter().rev() {
                    stack.push(*child);
                }
            }
            NativeAstChild::Token(index) => {
                rows.push(1);
                rows.push(arena.token_source.token_info(index)?.id);
            }
        }
    }
    Ok(rows)
}

fn native_ast_descendant_packed_id_rows(
    arena: &NativeAstArena,
    node_index: usize,
) -> PhpResult<Vec<i64>> {
    let node = arena.node(node_index)?;
    let mut rows = Vec::with_capacity(node.descendant_count);
    let mut stack = arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        match child {
            NativeAstChild::Node(index) => {
                let node = arena.node(index)?;
                rows.push(native_ast_pack_kind_id(0, node.rule_id));
                for child in node.children.iter().rev() {
                    stack.push(*child);
                }
            }
            NativeAstChild::Token(index) => {
                rows.push(native_ast_pack_kind_id(
                    1,
                    arena.token_source.token_info(index)?.id,
                ));
            }
        }
    }
    Ok(rows)
}

fn native_ast_descendant_scalar_rows(
    arena: &NativeAstArena,
    node_index: usize,
) -> PhpResult<Vec<i64>> {
    let node = arena.node(node_index)?;
    let mut rows = Vec::with_capacity(node.descendant_count.saturating_mul(4));
    let mut stack = arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        match child {
            NativeAstChild::Node(index) => {
                let node = arena.node(index)?;
                rows.push(0);
                rows.push(node.rule_id);
                // Keep the PHP and native benchmark rows directly comparable:
                // PHP parser nodes don't store spans, so node rows carry no
                // span data. Token rows below carry public token start/length.
                rows.push(-1);
                rows.push(0);
                for child in node.children.iter().rev() {
                    stack.push(*child);
                }
            }
            NativeAstChild::Token(index) => {
                let token = arena.token_source.token_info(index)?;
                rows.push(1);
                rows.push(token.id);
                rows.push(token.start as i64);
                rows.push(token.end.saturating_sub(token.start) as i64);
            }
        }
    }
    Ok(rows)
}

fn native_ast_descendant_packed_scalar_rows(
    arena: &NativeAstArena,
    node_index: usize,
) -> PhpResult<Vec<i64>> {
    let node = arena.node(node_index)?;
    let mut rows = Vec::with_capacity(node.descendant_count.saturating_mul(2));
    let mut stack = arena.descendant_stack(node_index)?;
    while let Some(child) = stack.pop() {
        match child {
            NativeAstChild::Node(index) => {
                let node = arena.node(index)?;
                rows.push(native_ast_pack_kind_id(0, node.rule_id));
                rows.push(-1);
                for child in node.children.iter().rev() {
                    stack.push(*child);
                }
            }
            NativeAstChild::Token(index) => {
                let token = arena.token_source.token_info(index)?;
                rows.push(native_ast_pack_kind_id(1, token.id));
                rows.push(native_ast_pack_span(
                    token.start,
                    token.end.saturating_sub(token.start),
                ));
            }
        }
    }
    Ok(rows)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_native_descendant_id_rows(
    wrapper_zval: &Zval,
    handle: i64,
) -> PhpResult<Vec<i64>> {
    let (ast, _node_index) = native_ast_from_wrapper(wrapper_zval)?;
    native_ast_descendant_id_rows(&ast.arena, native_ast_node_index_from_handle(handle)?)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_native_descendant_packed_id_rows(
    wrapper_zval: &Zval,
    handle: i64,
) -> PhpResult<Vec<i64>> {
    let (ast, _node_index) = native_ast_from_wrapper(wrapper_zval)?;
    native_ast_descendant_packed_id_rows(&ast.arena, native_ast_node_index_from_handle(handle)?)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_native_descendant_scalar_rows(
    wrapper_zval: &Zval,
    handle: i64,
) -> PhpResult<Vec<i64>> {
    let (ast, _node_index) = native_ast_from_wrapper(wrapper_zval)?;
    native_ast_descendant_scalar_rows(&ast.arena, native_ast_node_index_from_handle(handle)?)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_native_descendant_packed_scalar_rows(
    wrapper_zval: &Zval,
    handle: i64,
) -> PhpResult<Vec<i64>> {
    let (ast, _node_index) = native_ast_from_wrapper(wrapper_zval)?;
    native_ast_descendant_packed_scalar_rows(&ast.arena, native_ast_node_index_from_handle(handle)?)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_native_handle_kind(
    wrapper_zval: &Zval,
    handle: i64,
) -> PhpResult<i64> {
    let (ast, _node_index) = native_ast_from_wrapper(wrapper_zval)?;
    match native_ast_child_from_handle(handle)? {
        NativeAstChild::Node(index) => {
            ast.arena.node(index)?;
            Ok(0)
        }
        NativeAstChild::Token(index) => {
            ast.arena.token_source.token_info(index)?;
            Ok(1)
        }
    }
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_native_handle_id(
    wrapper_zval: &Zval,
    handle: i64,
) -> PhpResult<i64> {
    let (ast, _node_index) = native_ast_from_wrapper(wrapper_zval)?;
    match native_ast_child_from_handle(handle)? {
        NativeAstChild::Node(index) => Ok(ast.arena.node(index)?.rule_id),
        NativeAstChild::Token(index) => Ok(ast.arena.token_source.token_info(index)?.id),
    }
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_start(wrapper_zval: &Zval) -> PhpResult<i64> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let node = ast.arena.node(node_index)?;
    let token_index = node
        .first_token
        .ok_or_else(|| php_error("Native AST node has no descendant tokens"))?;
    let token = ast.arena.token_source.token_info(token_index)?;
    i64::try_from(token.start).map_err(php_error)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_length(wrapper_zval: &Zval) -> PhpResult<i64> {
    let (ast, node_index) = native_ast_from_wrapper(wrapper_zval)?;
    let node = ast.arena.node(node_index)?;
    let first_token_index = node
        .first_token
        .ok_or_else(|| php_error("Native AST node has no descendant tokens"))?;
    let last_token_index = node
        .last_token
        .ok_or_else(|| php_error("Native AST node has no descendant tokens"))?;
    let first_token = ast.arena.token_source.token_info(first_token_index)?;
    let last_token = ast.arena.token_source.token_info(last_token_index)?;
    let length = last_token.end.saturating_sub(first_token.start);
    i64::try_from(length).map_err(php_error)
}

#[php_class]
#[php(name = "WP_MySQL_Native_Parser")]
pub struct WpMySqlNativeParser {
    grammar: Arc<Grammar>,
    token_source: Arc<ParserTokenSource>,
    token_ids: Vec<i64>,
    position: usize,
    current_ast: Option<Rc<NativeAstState>>,
    current_php_ast: Option<Zval>,
}

#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpMySqlNativeParser {
    pub fn __construct(grammar: &mut Zval, tokens: &mut Zval) -> PhpResult<Self> {
        let grammar = export_grammar(grammar)?;
        let (token_source, token_ids) = export_tokens(tokens)?;

        Ok(Self {
            grammar,
            token_source: Arc::new(token_source),
            token_ids,
            position: 0,
            current_ast: None,
            current_php_ast: None,
        })
    }

    pub fn reset_tokens(&mut self, tokens: &mut Zval) -> PhpResult<()> {
        let (token_source, token_ids) = export_tokens(tokens)?;

        self.token_source = Arc::new(token_source);
        self.token_ids = token_ids;
        self.position = 0;
        self.current_ast = None;
        self.current_php_ast = None;

        Ok(())
    }

    pub fn parse(&mut self) -> PhpResult<Zval> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            let ast = self.parse_native_ast()?;
            self.create_php_ast(ast)
        })
    }

    pub fn parse_native_descendant_id_rows(&mut self) -> PhpResult<Option<Vec<i64>>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.parse_native_descendant_rows(NativeAstRowFormat::Id)
        })
    }

    pub fn parse_native_descendant_packed_id_rows(&mut self) -> PhpResult<Option<Vec<i64>>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.parse_native_descendant_rows(NativeAstRowFormat::PackedId)
        })
    }

    pub fn parse_native_descendant_scalar_rows(&mut self) -> PhpResult<Option<Vec<i64>>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.parse_native_descendant_rows(NativeAstRowFormat::Scalar)
        })
    }

    pub fn parse_native_descendant_packed_scalar_rows(&mut self) -> PhpResult<Option<Vec<i64>>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.parse_native_descendant_rows(NativeAstRowFormat::PackedScalar)
        })
    }

    pub fn parse_native_descendant_packed_id_stats(&mut self) -> PhpResult<Option<Vec<i64>>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.parse_native_descendant_stats(NativeAstStatsFormat::PackedId)
        })
    }

    pub fn parse_native_descendant_packed_scalar_stats(
        &mut self,
        consume_token_bytes: Option<bool>,
    ) -> PhpResult<Option<Vec<i64>>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.parse_native_descendant_stats(NativeAstStatsFormat::PackedScalar {
                consume_token_bytes: consume_token_bytes.unwrap_or(false),
            })
        })
    }

    pub fn parse_sql_native_descendant_packed_id_stats(
        &mut self,
        sql: &Zval,
        mysql_version: Option<i64>,
        sql_modes: Option<Vec<String>>,
    ) -> PhpResult<Option<Vec<i64>>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.parse_sql_native_packed_id_stats(sql, mysql_version, sql_modes)
        })
    }

    pub fn parse_sql_native_descendant_packed_scalar_stats(
        &mut self,
        sql: &Zval,
        consume_token_bytes: Option<bool>,
        mysql_version: Option<i64>,
        sql_modes: Option<Vec<String>>,
    ) -> PhpResult<Option<Vec<i64>>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.reset_sql(sql, mysql_version, sql_modes)?;
            self.parse_native_descendant_stats(NativeAstStatsFormat::PackedScalar {
                consume_token_bytes: consume_token_bytes.unwrap_or(false),
            })
        })
    }

    pub fn parse_sql_batch_native_descendant_packed_id_stats(
        &mut self,
        queries: &Zval,
        mysql_version: Option<i64>,
        sql_modes: Option<Vec<String>>,
    ) -> PhpResult<Vec<i64>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.parse_sql_batch_native_descendant_stats(
                queries,
                NativeAstStatsFormat::PackedId,
                mysql_version,
                sql_modes,
            )
        })
    }

    pub fn parse_sql_batch_native_descendant_packed_scalar_stats(
        &mut self,
        queries: &Zval,
        consume_token_bytes: Option<bool>,
        mysql_version: Option<i64>,
        sql_modes: Option<Vec<String>>,
    ) -> PhpResult<Vec<i64>> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            self.parse_sql_batch_native_descendant_stats(
                queries,
                NativeAstStatsFormat::PackedScalar {
                    consume_token_bytes: consume_token_bytes.unwrap_or(false),
                },
                mysql_version,
                sql_modes,
            )
        })
    }

    pub fn next_query(&mut self) -> PhpResult<bool> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || self.next_query_inner())
    }

    pub fn get_query_ast(&mut self) -> PhpResult<Zval> {
        if let Some(ast) = self.current_php_ast.as_ref() {
            return Ok(ast.shallow_clone());
        }

        let Some(native_ast) = self.current_ast.as_ref() else {
            return Ok(Zval::null());
        };

        let ast = self.create_php_ast(Rc::clone(native_ast))?;
        self.current_php_ast = Some(ast);
        match self.current_php_ast.as_ref() {
            Some(ast) => Ok(ast.shallow_clone()),
            None => Ok(Zval::null()),
        }
    }
}

impl WpMySqlNativeParser {
    fn next_query_inner(&mut self) -> PhpResult<bool> {
        if self.position >= self.token_ids.len() {
            self.current_ast = None;
            self.current_php_ast = None;
            return Ok(false);
        }

        self.current_ast = Some(self.parse_native_ast()?);
        self.current_php_ast = None;
        Ok(true)
    }

    fn parse_native_ast(&mut self) -> PhpResult<Rc<NativeAstState>> {
        Ok(NativeAstState::new(Arc::new(self.parse_native_arena()?)))
    }

    fn parse_native_arena(&mut self) -> PhpResult<NativeAstArena> {
        let mut arena =
            NativeAstArena::new(Arc::clone(&self.grammar), Arc::clone(&self.token_source));
        let query_rule_id = self.grammar.query_rule_id;
        arena.root = match self.parse_recursive_inner(&mut arena, query_rule_id)? {
            NativeParseMatch::No => NativeAstRoot::No,
            NativeParseMatch::Empty => NativeAstRoot::Empty,
            NativeParseMatch::Node(index) => NativeAstRoot::Node(index),
            NativeParseMatch::Token(index) => NativeAstRoot::Token(index),
            NativeParseMatch::Fragment(children) => {
                if children.is_empty() {
                    NativeAstRoot::Empty
                } else {
                    NativeAstRoot::Node(arena.push_node(query_rule_id, children))
                }
            }
        };
        Ok(arena)
    }

    fn parse_native_descendant_rows(
        &mut self,
        format: NativeAstRowFormat,
    ) -> PhpResult<Option<Vec<i64>>> {
        let mut rows = NativeAstRows::with_token_capacity(self.token_ids.len(), format);
        match self.parse_recursive_rows(self.grammar.query_rule_id, &mut rows, true)? {
            NativeDirectParseMatch::No => Ok(None),
            NativeDirectParseMatch::Empty | NativeDirectParseMatch::Match => {
                Ok(Some(rows.into_vec()))
            }
        }
    }

    fn parse_native_descendant_stats(
        &mut self,
        format: NativeAstStatsFormat,
    ) -> PhpResult<Option<Vec<i64>>> {
        Ok(self
            .parse_native_descendant_stats_inner(format)?
            .map(NativeAstStats::into_vec))
    }

    fn parse_native_descendant_stats_inner(
        &mut self,
        format: NativeAstStatsFormat,
    ) -> PhpResult<Option<NativeAstStats>> {
        if matches!(format, NativeAstStatsFormat::PackedId) {
            return Ok(self.parse_packed_id_stats_for_token_ids(&self.token_ids));
        }

        let sql_bytes = match format {
            NativeAstStatsFormat::PackedScalar {
                consume_token_bytes: true,
            } => self.token_source.sql_bytes(),
            _ => None,
        };
        let mut stats = NativeAstStats::new(format, sql_bytes);
        match self.parse_recursive_stats(self.grammar.query_rule_id, &mut stats, true)? {
            NativeDirectParseMatch::No => Ok(None),
            NativeDirectParseMatch::Empty | NativeDirectParseMatch::Match => Ok(Some(stats)),
        }
    }

    fn reset_sql(
        &mut self,
        sql: &Zval,
        mysql_version: Option<i64>,
        sql_modes: Option<Vec<String>>,
    ) -> PhpResult<()> {
        let sql_modes = sql_modes
            .as_ref()
            .map(|modes| sql_modes_mask(modes))
            .unwrap_or(0);
        self.reset_sql_with_modes_mask(sql, mysql_version, sql_modes)
    }

    fn reset_sql_with_modes_mask(
        &mut self,
        sql: &Zval,
        mysql_version: Option<i64>,
        sql_modes: i64,
    ) -> PhpResult<()> {
        let lexer =
            WpMySqlNativeLexer::from_raw_sql_zval_with_modes_mask(sql, mysql_version, sql_modes)?;
        let (token_source, token_ids) = lexer.into_raw_token_source();
        self.token_ids = token_ids;
        self.token_source = Arc::new(token_source);
        self.position = 0;
        self.current_ast = None;
        self.current_php_ast = None;
        Ok(())
    }

    fn parse_sql_native_packed_id_stats(
        &self,
        sql: &Zval,
        mysql_version: Option<i64>,
        sql_modes: Option<Vec<String>>,
    ) -> PhpResult<Option<Vec<i64>>> {
        let sql_modes = sql_modes
            .as_ref()
            .map(|modes| sql_modes_mask(modes))
            .unwrap_or(0);
        let lexer =
            WpMySqlNativeLexer::from_raw_sql_zval_with_modes_mask(sql, mysql_version, sql_modes)?;
        Ok(self
            .parse_packed_id_stats_for_token_ids(&lexer.into_token_ids())
            .map(NativeAstStats::into_vec))
    }

    fn parse_sql_batch_native_descendant_stats(
        &mut self,
        queries: &Zval,
        format: NativeAstStatsFormat,
        mysql_version: Option<i64>,
        sql_modes: Option<Vec<String>>,
    ) -> PhpResult<Vec<i64>> {
        let queries = queries
            .array()
            .ok_or_else(|| php_error("Parser batch queries must be an array"))?;
        let mut processed = 0;
        let mut failures = 0;
        let mut descendants = 0;
        let mut checksum = 0;
        let sql_modes = sql_modes
            .as_ref()
            .map(|modes| sql_modes_mask(modes))
            .unwrap_or(0);

        for (_, query) in queries {
            processed += 1;
            match format {
                NativeAstStatsFormat::PackedId => {
                    let lexer = WpMySqlNativeLexer::from_raw_sql_zval_with_modes_mask(
                        query,
                        mysql_version,
                        sql_modes,
                    )?;
                    match self.parse_packed_id_stats_for_token_ids(&lexer.into_token_ids()) {
                        Some(stats) => {
                            descendants += stats.descendants;
                            checksum += stats.checksum;
                        }
                        None => {
                            failures += 1;
                        }
                    }
                }
                NativeAstStatsFormat::PackedScalar { .. } => {
                    self.reset_sql_with_modes_mask(query, mysql_version, sql_modes)?;
                    match self.parse_native_descendant_stats_inner(format)? {
                        Some(stats) => {
                            descendants += stats.descendants;
                            checksum += stats.checksum;
                        }
                        None => {
                            failures += 1;
                        }
                    }
                }
            }
        }

        Ok(vec![processed, failures, descendants, checksum])
    }

    fn parse_packed_id_stats_for_token_ids(&self, token_ids: &[i64]) -> Option<NativeAstStats> {
        if self.grammar.highest_terminal_id == compiled_packed_id_parser::HIGHEST_TERMINAL_ID
            && self.grammar.query_rule_id == compiled_packed_id_parser::QUERY_RULE_ID
            && self.grammar.select_statement_rule_id
                == Some(compiled_packed_id_parser::SELECT_STATEMENT_RULE_ID)
        {
            return compiled_packed_id_parser::CompiledPackedIdStatsParser::new(token_ids)
                .parse()
                .map(|(descendants, checksum)| NativeAstStats {
                    descendants,
                    checksum,
                    format: NativeAstStatsFormat::PackedId,
                    sql_bytes: None,
                });
        }

        let mut stats = NativeAstStats::new(NativeAstStatsFormat::PackedId, None);
        let mut position = 0;
        match parse_recursive_packed_id_stats(
            &self.grammar,
            token_ids,
            &mut position,
            self.grammar.query_rule_id,
            &mut stats,
            true,
        ) {
            NativeDirectParseMatch::No => None,
            NativeDirectParseMatch::Empty | NativeDirectParseMatch::Match => Some(stats),
        }
    }

    fn parse_recursive_rows(
        &mut self,
        rule_id: i64,
        rows: &mut NativeAstRows,
        skip_current_node: bool,
    ) -> PhpResult<NativeDirectParseMatch> {
        if rule_id <= self.grammar.highest_terminal_id {
            if self.position >= self.token_ids.len() {
                return Ok(NativeDirectParseMatch::No);
            }
            if rule_id == 0 {
                return Ok(NativeDirectParseMatch::Empty);
            }
            if self.token_ids[self.position] == rule_id {
                let token_index = self.position;
                self.position += 1;
                match rows.format {
                    NativeAstRowFormat::Id | NativeAstRowFormat::PackedId => {
                        rows.push_token_id(rule_id);
                    }
                    NativeAstRowFormat::Scalar | NativeAstRowFormat::PackedScalar => {
                        rows.push_token(self.token_source.token_info(token_index)?);
                    }
                }
                return Ok(NativeDirectParseMatch::Match);
            }
            return Ok(NativeDirectParseMatch::No);
        }

        let grammar = unsafe {
            // The parser owns an Arc to immutable grammar data for its full lifetime.
            // Taking a raw shared reference avoids cloning hot branches just to satisfy
            // the borrow checker while recursive parsing mutates only `position`.
            &*Arc::as_ptr(&self.grammar)
        };

        let Some(rule) = grammar.rule(rule_id) else {
            return Ok(NativeDirectParseMatch::No);
        };
        if rule.branches.is_empty() {
            return Ok(NativeDirectParseMatch::No);
        }

        if let Some(first_set) = rule.first_set.as_ref() {
            let token_id = self.token_ids.get(self.position).copied().unwrap_or(0);
            if !first_set.contains(token_id) && !rule.nullable {
                return Ok(NativeDirectParseMatch::No);
            }
        } else if !rule.nullable {
            return Ok(NativeDirectParseMatch::No);
        }

        let starting_position = self.position;
        let starting_row_count = rows.len();

        for branch in &rule.branches {
            self.position = starting_position;
            rows.truncate(starting_row_count);
            let emit_node = !skip_current_node && !rule.is_fragment;
            if emit_node {
                rows.push_node(rule_id);
            }
            let mut branch_matches = true;
            let mut has_children = false;

            for &subrule_id in branch {
                let child_starting_row_count = rows.len();
                match self.parse_recursive_rows(subrule_id, rows, false)? {
                    NativeDirectParseMatch::No => {
                        branch_matches = false;
                        break;
                    }
                    NativeDirectParseMatch::Empty => {}
                    NativeDirectParseMatch::Match => {
                        if rows.len() != child_starting_row_count {
                            has_children = true;
                        }
                    }
                }
            }

            if branch_matches
                && grammar.select_statement_rule_id == Some(rule_id)
                && self
                    .token_ids
                    .get(self.position)
                    .is_some_and(|token_id| *token_id == lex::INTO_SYMBOL)
            {
                branch_matches = false;
            }

            if branch_matches {
                if has_children {
                    return Ok(NativeDirectParseMatch::Match);
                }
                rows.truncate(starting_row_count);
                return Ok(NativeDirectParseMatch::Empty);
            }
        }

        self.position = starting_position;
        rows.truncate(starting_row_count);
        Ok(NativeDirectParseMatch::No)
    }

    fn parse_recursive_stats(
        &mut self,
        rule_id: i64,
        stats: &mut NativeAstStats,
        skip_current_node: bool,
    ) -> PhpResult<NativeDirectParseMatch> {
        if rule_id <= self.grammar.highest_terminal_id {
            if self.position >= self.token_ids.len() {
                return Ok(NativeDirectParseMatch::No);
            }
            if rule_id == 0 {
                return Ok(NativeDirectParseMatch::Empty);
            }
            if self.token_ids[self.position] == rule_id {
                let token_index = self.position;
                self.position += 1;
                match stats.format {
                    NativeAstStatsFormat::PackedId => {
                        stats.push_token_id(rule_id);
                    }
                    NativeAstStatsFormat::PackedScalar { .. } => {
                        stats.push_token(self.token_source.token_info(token_index)?);
                    }
                }
                return Ok(NativeDirectParseMatch::Match);
            }
            return Ok(NativeDirectParseMatch::No);
        }

        let grammar = unsafe {
            // The parser owns an Arc to immutable grammar data for its full lifetime.
            // Taking a raw shared reference avoids cloning hot branches just to satisfy
            // the borrow checker while recursive parsing mutates only `position`.
            &*Arc::as_ptr(&self.grammar)
        };

        let Some(rule) = grammar.rule(rule_id) else {
            return Ok(NativeDirectParseMatch::No);
        };
        if rule.branches.is_empty() {
            return Ok(NativeDirectParseMatch::No);
        }

        if let Some(first_set) = rule.first_set.as_ref() {
            let token_id = self.token_ids.get(self.position).copied().unwrap_or(0);
            if !first_set.contains(token_id) && !rule.nullable {
                return Ok(NativeDirectParseMatch::No);
            }
        } else if !rule.nullable {
            return Ok(NativeDirectParseMatch::No);
        }

        let starting_position = self.position;
        let starting_stats = stats.state();

        for branch in &rule.branches {
            self.position = starting_position;
            stats.restore(starting_stats);
            let emit_node = !skip_current_node && !rule.is_fragment;
            if emit_node {
                stats.push_node(rule_id);
            }
            let mut branch_matches = true;
            let mut has_children = false;

            for &subrule_id in branch {
                let child_starting_descendants = stats.descendants;
                match self.parse_recursive_stats(subrule_id, stats, false)? {
                    NativeDirectParseMatch::No => {
                        branch_matches = false;
                        break;
                    }
                    NativeDirectParseMatch::Empty => {}
                    NativeDirectParseMatch::Match => {
                        if stats.descendants != child_starting_descendants {
                            has_children = true;
                        }
                    }
                }
            }

            if branch_matches
                && grammar.select_statement_rule_id == Some(rule_id)
                && self
                    .token_ids
                    .get(self.position)
                    .is_some_and(|token_id| *token_id == lex::INTO_SYMBOL)
            {
                branch_matches = false;
            }

            if branch_matches {
                if has_children {
                    return Ok(NativeDirectParseMatch::Match);
                }
                stats.restore(starting_stats);
                return Ok(NativeDirectParseMatch::Empty);
            }
        }

        self.position = starting_position;
        stats.restore(starting_stats);
        Ok(NativeDirectParseMatch::No)
    }

    fn parse_recursive_inner(
        &mut self,
        arena: &mut NativeAstArena,
        rule_id: i64,
    ) -> PhpResult<NativeParseMatch> {
        if rule_id <= self.grammar.highest_terminal_id {
            if self.position >= self.token_ids.len() {
                return Ok(NativeParseMatch::No);
            }
            if rule_id == 0 {
                return Ok(NativeParseMatch::Empty);
            }
            if self.token_ids[self.position] == rule_id {
                let token_index = self.position;
                self.position += 1;
                return Ok(NativeParseMatch::Token(token_index));
            }
            return Ok(NativeParseMatch::No);
        }

        let grammar = unsafe {
            // The parser owns an Arc to immutable grammar data for its full lifetime.
            // Taking a raw shared reference avoids cloning hot branches just to satisfy
            // the borrow checker while recursive parsing mutates only `position`.
            &*Arc::as_ptr(&self.grammar)
        };

        let Some(rule) = grammar.rule(rule_id) else {
            return Ok(NativeParseMatch::No);
        };
        if rule.branches.is_empty() {
            return Ok(NativeParseMatch::No);
        }

        if let Some(first_set) = rule.first_set.as_ref() {
            let token_id = self.token_ids.get(self.position).copied().unwrap_or(0);
            if !first_set.contains(token_id) && !rule.nullable {
                return Ok(NativeParseMatch::No);
            }
        } else if !rule.nullable {
            return Ok(NativeParseMatch::No);
        }

        let starting_position = self.position;
        let starting_node_count = arena.nodes.len();
        let mut matched_children = None;

        for branch in &rule.branches {
            self.position = starting_position;
            arena.nodes.truncate(starting_node_count);
            let mut children = Vec::new();
            let mut branch_matches = true;

            for &subrule_id in branch {
                match self.parse_recursive_inner(arena, subrule_id)? {
                    NativeParseMatch::No => {
                        branch_matches = false;
                        break;
                    }
                    NativeParseMatch::Empty => {}
                    NativeParseMatch::Token(token_index) => {
                        children.push(NativeAstChild::Token(token_index));
                    }
                    NativeParseMatch::Node(node_index) => {
                        children.push(NativeAstChild::Node(node_index));
                    }
                    NativeParseMatch::Fragment(fragment_children) => {
                        children.extend(fragment_children);
                    }
                }
            }

            if branch_matches
                && grammar.select_statement_rule_id == Some(rule_id)
                && self
                    .token_ids
                    .get(self.position)
                    .is_some_and(|token_id| *token_id == lex::INTO_SYMBOL)
            {
                branch_matches = false;
            }

            if branch_matches {
                matched_children = Some(children);
                break;
            }
        }

        let Some(children) = matched_children else {
            self.position = starting_position;
            arena.nodes.truncate(starting_node_count);
            return Ok(NativeParseMatch::No);
        };

        if children.is_empty() {
            Ok(NativeParseMatch::Empty)
        } else if rule.is_fragment {
            Ok(NativeParseMatch::Fragment(children))
        } else {
            Ok(NativeParseMatch::Node(arena.push_node(rule_id, children)))
        }
    }

    fn create_php_ast(&self, ast: Rc<NativeAstState>) -> PhpResult<Zval> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || ast.create_php_ast())
    }
}

fn export_grammar(grammar_zval: &mut Zval) -> PhpResult<Arc<Grammar>> {
    if let Some(cached) = cached_native_grammar(grammar_zval)? {
        return Ok(cached);
    }

    let exported = php_function("wp_sqlite_mysql_native_export_grammar")?
        .try_call(vec![&*grammar_zval as &dyn IntoZvalDyn])
        .map_err(php_error)?;
    let array = exported
        .array()
        .ok_or_else(|| php_error("Exported grammar must be an array"))?;

    let highest_terminal_id = array
        .get("highest_terminal_id")
        .and_then(Zval::long)
        .ok_or_else(|| php_error("Missing grammar highest_terminal_id"))?;
    let parsed_rules = parse_rules(
        array
            .get("rules")
            .and_then(Zval::array)
            .ok_or_else(|| php_error("Missing grammar rules"))?,
    )?;
    let parsed_first_sets = parse_first_sets(
        array
            .get("first_sets")
            .and_then(Zval::array)
            .ok_or_else(|| php_error("Missing grammar first_sets"))?,
    )?;
    let parsed_nullable = parse_id_set(
        array
            .get("nullable_branches")
            .and_then(Zval::array)
            .ok_or_else(|| php_error("Missing grammar nullable_branches"))?,
    )?;
    let parsed_rule_names = parse_rule_names(
        array
            .get("rule_names")
            .and_then(Zval::array)
            .ok_or_else(|| php_error("Missing grammar rule_names"))?,
    )?;
    let parsed_fragment_ids = parse_id_set(
        array
            .get("fragment_ids")
            .and_then(Zval::array)
            .ok_or_else(|| php_error("Missing grammar fragment_ids"))?,
    )?;
    let query_rule_id = parsed_rule_names
        .iter()
        .find_map(|(id, name)| (name == "query").then_some(*id))
        .ok_or_else(|| php_error("Missing query grammar rule"))?;
    let select_statement_rule_id = parsed_rule_names
        .iter()
        .find_map(|(id, name)| (name == "selectStatement").then_some(*id));
    let rules = build_rules(
        parsed_rules,
        parsed_first_sets,
        parsed_nullable,
        parsed_rule_names,
        parsed_fragment_ids,
    )?;

    let grammar = Arc::new(Grammar {
        highest_terminal_id,
        rules,
        query_rule_id,
        select_statement_rule_id,
    });

    cache_native_grammar(grammar_zval, Arc::clone(&grammar))?;

    Ok(grammar)
}

fn cached_native_grammar(grammar: &Zval) -> PhpResult<Option<Arc<Grammar>>> {
    let object = grammar
        .object()
        .ok_or_else(|| php_error("Parser grammar must be an object"))?;
    let properties = object.get_properties().map_err(php_error)?;
    let Some(native_grammar) = properties.get("native_grammar") else {
        return Ok(None);
    };
    let Some(native_grammar) = <&WpMySqlNativeGrammar as FromZval>::from_zval(native_grammar)
    else {
        return Ok(None);
    };

    Ok(Some(Arc::clone(&native_grammar.grammar)))
}

fn cache_native_grammar(grammar_zval: &mut Zval, grammar: Arc<Grammar>) -> PhpResult<()> {
    let object = grammar_zval
        .object_mut()
        .ok_or_else(|| php_error("Parser grammar must be an object"))?;
    let native_grammar = WpMySqlNativeGrammar { grammar }
        .into_zval(false)
        .map_err(php_error)?;
    object
        .set_property("native_grammar", native_grammar)
        .map_err(php_error)
}

fn export_tokens(tokens: &mut Zval) -> PhpResult<(ParserTokenSource, Vec<i64>)> {
    if let Some(stream) = <&WpMySqlNativeTokenStream as FromZval>::from_zval(tokens) {
        let token_ids = stream.tokens.iter().map(|token| token.id).collect();
        return Ok((
            ParserTokenSource::Native {
                sql_zval: stream.sql_zval.shallow_clone(),
                tokens: stream.tokens.clone(),
                no_backslash: stream.no_backslash,
            },
            token_ids,
        ));
    }

    let array = tokens
        .array()
        .ok_or_else(|| php_error("Parser tokens must be an array"))?;
    let mut token_objects = Vec::with_capacity(array.len());
    let mut token_ids = Vec::with_capacity(array.len());

    for (_, token) in array {
        let token_object = token
            .object()
            .ok_or_else(|| php_error("Parser token must be an object"))?;
        let id = token_object.get_property::<i64>("id").map_err(php_error)?;
        token_objects.push(token.shallow_clone());
        token_ids.push(id);
    }

    Ok((ParserTokenSource::Php(token_objects), token_ids))
}

fn build_rules(
    rules: HashMap<i64, Vec<Vec<i64>>>,
    first_sets: HashMap<i64, HashSet<i64>>,
    nullable: HashSet<i64>,
    rule_names: HashMap<i64, String>,
    fragment_ids: HashSet<i64>,
) -> PhpResult<Vec<Option<Rule>>> {
    let max_rule_id = rules
        .keys()
        .chain(first_sets.keys())
        .chain(nullable.iter())
        .chain(rule_names.keys())
        .chain(fragment_ids.iter())
        .copied()
        .max()
        .unwrap_or(0);
    let max_rule_index = usize::try_from(max_rule_id).map_err(php_error)?;
    let mut dense_rules: Vec<Option<Rule>> = (0..=max_rule_index).map(|_| None).collect();

    for (rule_id, branches) in rules {
        let index = usize::try_from(rule_id).map_err(php_error)?;
        let first_set = first_sets
            .get(&rule_id)
            .and_then(|set| FirstSet::from_ids(set.iter().copied().collect()));

        dense_rules[index] = Some(Rule {
            branches,
            first_set,
            nullable: nullable.contains(&rule_id),
            rule_name: rule_names.get(&rule_id).cloned().unwrap_or_default(),
            is_fragment: fragment_ids.contains(&rule_id),
        });
    }

    Ok(dense_rules)
}

fn parse_rules(array: &ZendHashTable) -> PhpResult<HashMap<i64, Vec<Vec<i64>>>> {
    let mut rules = HashMap::new();
    for (rule_key, branches_zval) in array {
        let rule_id = array_key_to_i64(rule_key)?;
        let branches_array = branches_zval
            .array()
            .ok_or_else(|| php_error("Grammar branches must be arrays"))?;
        let mut branches = Vec::with_capacity(branches_array.len());
        for (_, branch_zval) in branches_array {
            let branch_array = branch_zval
                .array()
                .ok_or_else(|| php_error("Grammar branch must be an array"))?;
            let mut branch = Vec::with_capacity(branch_array.len());
            for (_, subrule_zval) in branch_array {
                branch.push(
                    subrule_zval
                        .long()
                        .ok_or_else(|| php_error("Grammar subrule must be an integer"))?,
                );
            }
            branches.push(branch);
        }
        rules.insert(rule_id, branches);
    }
    Ok(rules)
}

/// Parse the per-rule FIRST sets, keyed `[rule_id => [token_id => true]]`.
/// The native parser uses these to decide early whether a rule can start
/// with the current token; it builds its own branch candidates from
/// `rules`, so the pure-PHP parser's per-token selector table is not needed.
fn parse_first_sets(array: &ZendHashTable) -> PhpResult<HashMap<i64, HashSet<i64>>> {
    let mut first_sets = HashMap::new();
    for (rule_key, tokens_zval) in array {
        let rule_id = array_key_to_i64(rule_key)?;
        let tokens = tokens_zval
            .array()
            .ok_or_else(|| php_error("Grammar first_sets entry must be an array"))?;
        let mut set = HashSet::with_capacity(tokens.len());
        for (token_key, _) in tokens {
            set.insert(array_key_to_i64(token_key)?);
        }
        first_sets.insert(rule_id, set);
    }
    Ok(first_sets)
}

fn parse_rule_names(array: &ZendHashTable) -> PhpResult<HashMap<i64, String>> {
    let mut names = HashMap::new();
    for (rule_key, name_zval) in array {
        names.insert(
            array_key_to_i64(rule_key)?,
            name_zval
                .string()
                .ok_or_else(|| php_error("Grammar rule name must be a string"))?,
        );
    }
    Ok(names)
}

fn parse_id_set(array: &ZendHashTable) -> PhpResult<HashSet<i64>> {
    let mut set = HashSet::with_capacity(array.len());
    for (key, _) in array {
        set.insert(array_key_to_i64(key)?);
    }
    Ok(set)
}

fn array_key_to_i64(key: ArrayKey<'_>) -> PhpResult<i64> {
    match key {
        ArrayKey::Long(value) => Ok(value),
        ArrayKey::String(value) => value.parse::<i64>().map_err(php_error),
        ArrayKey::Str(value) => value.parse::<i64>().map_err(php_error),
    }
}

extern "C" fn php_module_info(_module: *mut ModuleEntry) {
    info_table_start!();
    info_table_row!("wp_mysql_parser", "enabled");
    info_table_end!();
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<WpMySqlNativeGrammar>()
        .class::<WpMySqlNativeTokenStream>()
        .class::<WpMySqlNativeLexer>()
        .class::<WpMySqlNativeParser>()
        .class::<WpSqliteNativeConnection>()
        .class::<WpSqliteNativePackedResult>()
        .class::<WpSqliteNativeStatement>()
        .function(wrap_function!(wp_sqlite_mysql_native_ast_release_wrapper))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_materialize_wrapper
        ))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_has_child))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_has_child_node))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_has_child_token))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_first_child))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_first_child_node
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_first_child_token
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_first_descendant_node
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_first_descendant_token
        ))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_children))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_child_nodes))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_child_tokens))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_descendants))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_descendant_nodes
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_descendant_tokens
        ))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_native_handle))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_native_child_handles
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_native_descendant_handles
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_native_descendant_id_rows
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_native_descendant_packed_id_rows
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_native_descendant_scalar_rows
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_native_descendant_packed_scalar_rows
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_native_handle_kind
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_native_handle_id
        ))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_start))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_length))
        .info_function(php_module_info)
}
