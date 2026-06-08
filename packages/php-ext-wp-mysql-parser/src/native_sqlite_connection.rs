use std::rc::Rc;
use std::time::Duration;

use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::PhpResult;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, ZendObject, Zval};
use rusqlite::types::ValueRef;

use crate::{native_sqlite_translator, php_error, BinaryString};

const FETCH_DEFAULT: i64 = 0;
const FETCH_ASSOC: i64 = 2;
const FETCH_NUM: i64 = 3;
const FETCH_BOTH: i64 = 4;
const FETCH_OBJ: i64 = 5;
const FETCH_COLUMN: i64 = 7;
const FETCH_NAMED: i64 = 11;

#[derive(Clone)]
enum NativeSqliteValue {
    Null,
    Bytes(Vec<u8>),
}

impl NativeSqliteValue {
    fn from_value_ref(value: ValueRef<'_>) -> Self {
        match value {
            ValueRef::Null => Self::Null,
            ValueRef::Integer(value) => Self::Bytes(value.to_string().into_bytes()),
            ValueRef::Real(value) => Self::Bytes(value.to_string().into_bytes()),
            ValueRef::Text(value) | ValueRef::Blob(value) => Self::Bytes(value.to_vec()),
        }
    }

    fn to_zval(&self) -> PhpResult<Zval> {
        match self {
            Self::Null => Ok(Zval::null()),
            Self::Bytes(value) => BinaryString(value.clone())
                .into_zval(false)
                .map_err(php_error),
        }
    }
}

#[php_class]
#[php(name = "WP_SQLite_Native_Connection")]
pub struct WpSqliteNativeConnection {
    connection: Rc<rusqlite::Connection>,
}

#[php_impl]
impl WpSqliteNativeConnection {
    pub fn __construct(path: String) -> PhpResult<Self> {
        let connection = rusqlite::Connection::open(path).map_err(php_error)?;
        connection
            .busy_timeout(Duration::from_secs(10))
            .map_err(php_error)?;
        connection
            .execute_batch("PRAGMA foreign_keys = ON")
            .map_err(php_error)?;
        Ok(Self {
            connection: Rc::new(connection),
        })
    }

    pub fn query(&self, sql: String) -> PhpResult<WpSqliteNativeStatement> {
        let sqlite_queries = vec![sql.clone()];
        let statement = self.prepare_query_statement(sql, -1, sqlite_queries)?;
        Ok(statement)
    }

    pub fn query_mysql(&self, sql: String) -> PhpResult<Option<WpSqliteNativeStatement>> {
        match native_sqlite_translator::translate_sqlite_plan_code(sql.as_bytes()) {
            native_sqlite_translator::PLAN_SELECT_ORIGINAL
            | native_sqlite_translator::PLAN_SELECT_FOUND_ROWS_CODE => return Ok(None),
            native_sqlite_translator::PLAN_UPDATE_ORIGINAL => {
                return Ok(Some(self.prepare_execute_statement(sql)?));
            }
            _ => {
                if !native_sqlite_translator::contains_sql_calc_found_rows(&sql) {
                    return Ok(None);
                }
            }
        }

        let Some(plan) = native_sqlite_translator::translate_sqlite_plan(sql.as_bytes()) else {
            return Ok(None);
        };

        match plan.first().map(String::as_str) {
            Some(native_sqlite_translator::SELECT_PASSTHROUGH) => {
                let Some(sqlite_query) = plan.get(1) else {
                    return Ok(None);
                };
                let Some(count_source_query) = plan.get(2).filter(|query| !query.is_empty()) else {
                    return Ok(None);
                };

                let count_query = format!("SELECT COUNT(*) AS cnt FROM ({count_source_query})");
                let found_rows = self
                    .connection
                    .query_row(&count_query, [], |row| row.get::<_, i64>(0))
                    .map_err(php_error)?;
                let statement = self.execute_query_statement(
                    sqlite_query.clone(),
                    found_rows,
                    vec![count_query, sqlite_query.clone()],
                )?;
                Ok(Some(statement))
            }

            Some(native_sqlite_translator::UPDATE_PASSTHROUGH) => {
                let Some(sqlite_query) = plan.get(1) else {
                    return Ok(None);
                };
                Ok(Some(self.prepare_execute_statement(sqlite_query.clone())?))
            }

            _ => Ok(None),
        }
    }

    pub fn query_mysql_packed_rows(
        &self,
        sql: String,
    ) -> PhpResult<Option<WpSqliteNativePackedResult>> {
        match native_sqlite_translator::translate_sqlite_plan_code(sql.as_bytes()) {
            native_sqlite_translator::PLAN_SELECT_ORIGINAL => {
                return self
                    .execute_packed_query_statement(sql.clone(), -1, vec![sql])
                    .map(Some);
            }
            native_sqlite_translator::PLAN_SELECT_FOUND_ROWS_CODE
            | native_sqlite_translator::PLAN_UPDATE_ORIGINAL => return Ok(None),
            _ => {
                if !native_sqlite_translator::contains_sql_calc_found_rows(&sql) {
                    return Ok(None);
                }
            }
        }

        let Some(plan) = native_sqlite_translator::translate_sqlite_plan(sql.as_bytes()) else {
            return Ok(None);
        };

        let Some(native_sqlite_translator::SELECT_PASSTHROUGH) = plan.first().map(String::as_str)
        else {
            return Ok(None);
        };
        let Some(sqlite_query) = plan.get(1) else {
            return Ok(None);
        };
        let Some(count_source_query) = plan.get(2).filter(|query| !query.is_empty()) else {
            return Ok(None);
        };

        let count_query = format!("SELECT COUNT(*) AS cnt FROM ({count_source_query})");
        let found_rows = self
            .connection
            .query_row(&count_query, [], |row| row.get::<_, i64>(0))
            .map_err(php_error)?;
        self.execute_packed_query_statement(
            sqlite_query.clone(),
            found_rows,
            vec![count_query, sqlite_query.clone()],
        )
        .map(Some)
    }

    pub fn execute(&self, sql: String) -> PhpResult<i64> {
        let affected_rows = self.connection.execute(&sql, []).map_err(php_error)?;
        i64::try_from(affected_rows).map_err(php_error)
    }

    pub fn execute_statement(&self, sql: String) -> PhpResult<WpSqliteNativeStatement> {
        self.prepare_execute_statement(sql)
    }
}

impl WpSqliteNativeConnection {
    fn prepare_query_statement(
        &self,
        sql: String,
        found_rows: i64,
        sqlite_queries: Vec<String>,
    ) -> PhpResult<WpSqliteNativeStatement> {
        let statement = self.connection.prepare(&sql).map_err(php_error)?;
        let columns = statement
            .column_names()
            .iter()
            .map(ToString::to_string)
            .collect::<Vec<_>>();
        drop(statement);

        Ok(WpSqliteNativeStatement {
            connection: Some(Rc::clone(&self.connection)),
            sql: Some(sql),
            columns,
            rows: Vec::new(),
            cursor: 0,
            loaded: false,
            exhausted: false,
            default_fetch_mode: FETCH_BOTH,
            affected_rows: 0,
            found_rows,
            sqlite_queries,
        })
    }

    fn execute_query_statement(
        &self,
        sql: String,
        found_rows: i64,
        sqlite_queries: Vec<String>,
    ) -> PhpResult<WpSqliteNativeStatement> {
        let mut statement = self.connection.prepare(&sql).map_err(php_error)?;
        let columns = statement
            .column_names()
            .iter()
            .map(ToString::to_string)
            .collect::<Vec<_>>();
        let column_count = columns.len();
        let mut result_rows = Vec::new();
        let mut rows = statement.query([]).map_err(php_error)?;
        while let Some(row) = rows.next().map_err(php_error)? {
            let mut values = Vec::with_capacity(column_count);
            for index in 0..column_count {
                values.push(NativeSqliteValue::from_value_ref(
                    row.get_ref(index).map_err(php_error)?,
                ));
            }
            result_rows.push(values);
        }

        Ok(WpSqliteNativeStatement {
            connection: None,
            sql: None,
            columns,
            rows: result_rows,
            cursor: 0,
            loaded: true,
            exhausted: false,
            default_fetch_mode: FETCH_BOTH,
            affected_rows: 0,
            found_rows,
            sqlite_queries,
        })
    }

    fn execute_packed_query_statement(
        &self,
        sql: String,
        found_rows: i64,
        sqlite_queries: Vec<String>,
    ) -> PhpResult<WpSqliteNativePackedResult> {
        let mut statement = self.connection.prepare(&sql).map_err(php_error)?;
        let columns = statement
            .column_names()
            .iter()
            .map(ToString::to_string)
            .collect::<Vec<_>>();
        let column_count = columns.len();
        let mut packed_rows = Vec::new();
        let mut checksum = 0i64;
        let mut row_count = 0i64;
        let mut rows = statement.query([]).map_err(php_error)?;
        while let Some(row) = rows.next().map_err(php_error)? {
            row_count += 1;
            for index in 0..column_count {
                append_packed_sqlite_value(
                    row.get_ref(index).map_err(php_error)?,
                    &mut packed_rows,
                    &mut checksum,
                );
            }
        }

        checksum += found_rows + row_count + i64::try_from(column_count).map_err(php_error)?;
        Ok(WpSqliteNativePackedResult {
            columns,
            rows: packed_rows,
            row_count,
            found_rows,
            checksum,
            sqlite_queries,
        })
    }

    fn prepare_execute_statement(&self, sql: String) -> PhpResult<WpSqliteNativeStatement> {
        let affected_rows = self.execute(sql.clone())?;
        Ok(WpSqliteNativeStatement {
            connection: None,
            sql: None,
            columns: Vec::new(),
            rows: Vec::new(),
            cursor: 0,
            loaded: true,
            exhausted: true,
            default_fetch_mode: FETCH_BOTH,
            affected_rows,
            found_rows: -1,
            sqlite_queries: vec![sql],
        })
    }
}

#[php_class]
#[php(name = "WP_SQLite_Native_Packed_Result")]
pub struct WpSqliteNativePackedResult {
    columns: Vec<String>,
    rows: Vec<u8>,
    row_count: i64,
    found_rows: i64,
    checksum: i64,
    sqlite_queries: Vec<String>,
}

#[php_impl]
impl WpSqliteNativePackedResult {
    pub fn column_count(&self) -> usize {
        self.columns.len()
    }

    pub fn columns(&self) -> Vec<String> {
        self.columns.clone()
    }

    pub fn row_count(&self) -> i64 {
        self.row_count
    }

    pub fn found_rows(&self) -> i64 {
        self.found_rows
    }

    pub fn checksum(&self) -> i64 {
        self.checksum
    }

    pub fn packed_rows(&self) -> PhpResult<Zval> {
        BinaryString(self.rows.clone())
            .into_zval(false)
            .map_err(php_error)
    }

    pub fn take_packed_rows(&mut self) -> PhpResult<Zval> {
        BinaryString(std::mem::take(&mut self.rows))
            .into_zval(false)
            .map_err(php_error)
    }

    pub fn sqlite_queries(&self) -> Vec<String> {
        self.sqlite_queries.clone()
    }
}

#[php_class]
#[php(name = "WP_SQLite_Native_Statement")]
pub struct WpSqliteNativeStatement {
    connection: Option<Rc<rusqlite::Connection>>,
    sql: Option<String>,
    columns: Vec<String>,
    rows: Vec<Vec<NativeSqliteValue>>,
    cursor: usize,
    loaded: bool,
    exhausted: bool,
    default_fetch_mode: i64,
    affected_rows: i64,
    found_rows: i64,
    sqlite_queries: Vec<String>,
}

#[php_impl]
impl WpSqliteNativeStatement {
    pub fn column_count(&self) -> usize {
        self.columns.len()
    }

    pub fn row_count(&self) -> i64 {
        self.affected_rows
    }

    pub fn result_row_count(&self) -> usize {
        self.rows.len()
    }

    pub fn found_rows(&self) -> i64 {
        self.found_rows
    }

    pub fn sqlite_queries(&self) -> Vec<String> {
        self.sqlite_queries.clone()
    }

    #[php(defaults(mode = Some(0), cursor_orientation = Some(0), cursor_offset = Some(0)))]
    pub fn fetch(
        &mut self,
        mode: Option<i64>,
        _cursor_orientation: Option<i64>,
        _cursor_offset: Option<i64>,
    ) -> PhpResult<Zval> {
        self.load_rows()?;
        if self.exhausted || self.cursor >= self.rows.len() {
            return false.into_zval(false).map_err(php_error);
        }

        let mode = self.resolve_fetch_mode(mode.unwrap_or(FETCH_DEFAULT));
        let row = self.row_to_zval(&self.rows[self.cursor], mode, 0)?;
        self.cursor += 1;
        Ok(row)
    }

    #[php(defaults(column = Some(0)))]
    pub fn fetch_column(&mut self, column: Option<i64>) -> PhpResult<Zval> {
        self.load_rows()?;
        if self.exhausted || self.cursor >= self.rows.len() {
            return false.into_zval(false).map_err(php_error);
        }

        let value = self.rows[self.cursor]
            .get(usize::try_from(column.unwrap_or(0)).unwrap_or(usize::MAX))
            .map(NativeSqliteValue::to_zval)
            .unwrap_or_else(|| false.into_zval(false).map_err(php_error))?;
        self.cursor += 1;
        Ok(value)
    }

    #[php(defaults(mode = Some(0), column = None, _constructor_args = None))]
    pub fn fetch_all(
        &mut self,
        mode: Option<i64>,
        column: Option<&Zval>,
        _constructor_args: Option<&Zval>,
    ) -> PhpResult<Zval> {
        let mode = self.resolve_fetch_mode(mode.unwrap_or(FETCH_DEFAULT));
        let column = column.and_then(Zval::long).unwrap_or(0);
        let column = usize::try_from(column).unwrap_or(usize::MAX);
        if !self.loaded && 0 == self.cursor {
            return self.fetch_all_from_sql(mode, column);
        }

        self.load_rows()?;
        let mut result = ZendHashTable::with_capacity(
            self.rows
                .len()
                .saturating_sub(self.cursor)
                .try_into()
                .unwrap_or(0),
        );
        while self.cursor < self.rows.len() {
            let row = if mode == FETCH_COLUMN {
                self.rows[self.cursor]
                    .get(column)
                    .map(NativeSqliteValue::to_zval)
                    .unwrap_or_else(|| false.into_zval(false).map_err(php_error))?
            } else {
                self.row_to_zval(&self.rows[self.cursor], mode, 0)?
            };
            result.push(row).map_err(php_error)?;
            self.cursor += 1;
        }
        self.exhausted = true;
        result.into_zval(false).map_err(php_error)
    }

    pub fn fetch_object(
        &mut self,
        _class: Option<String>,
        _constructor_args: Option<&ZendHashTable>,
    ) -> PhpResult<Zval> {
        self.fetch(Some(FETCH_OBJ), Some(0), Some(0))
    }

    pub fn get_column_meta(&self, column: i64) -> PhpResult<Zval> {
        let Some(name) = self
            .columns
            .get(usize::try_from(column).unwrap_or(usize::MAX))
        else {
            return false.into_zval(false).map_err(php_error);
        };

        let mut meta = ZendHashTable::with_capacity(7);
        meta.insert("native_type", "string").map_err(php_error)?;
        meta.insert("pdo_type", 2i64).map_err(php_error)?;
        meta.insert("flags", ZendHashTable::new())
            .map_err(php_error)?;
        meta.insert("table", "").map_err(php_error)?;
        meta.insert("name", name.as_str()).map_err(php_error)?;
        meta.insert("len", -1i64).map_err(php_error)?;
        meta.insert("precision", 0i64).map_err(php_error)?;
        meta.into_zval(false).map_err(php_error)
    }

    #[php(defaults(mode = 0))]
    pub fn set_fetch_mode(&mut self, mode: i64) -> bool {
        self.default_fetch_mode = mode;
        true
    }
}

fn append_packed_sqlite_value(value: ValueRef<'_>, output: &mut Vec<u8>, checksum: &mut i64) {
    match value {
        ValueRef::Null => append_packed_bytes(None, output, checksum),
        ValueRef::Integer(value) => {
            let mut buffer = itoa::Buffer::new();
            append_packed_bytes(Some(buffer.format(value).as_bytes()), output, checksum)
        }
        ValueRef::Real(value) => {
            append_packed_bytes(Some(value.to_string().as_bytes()), output, checksum)
        }
        ValueRef::Text(value) | ValueRef::Blob(value) => {
            append_packed_bytes(Some(value), output, checksum)
        }
    }
}

fn append_packed_bytes(value: Option<&[u8]>, output: &mut Vec<u8>, checksum: &mut i64) {
    let length = value
        .map(|bytes| u32::try_from(bytes.len()).unwrap_or(u32::MAX - 1))
        .unwrap_or(u32::MAX);
    for byte in length.to_le_bytes() {
        output.push(byte);
        *checksum += i64::from(byte);
    }
    if let Some(bytes) = value {
        output.extend_from_slice(bytes);
        for byte in bytes {
            *checksum += i64::from(*byte);
        }
    }
}

impl WpSqliteNativeStatement {
    fn load_rows(&mut self) -> PhpResult<()> {
        if self.loaded {
            return Ok(());
        }

        let Some(connection) = self.connection.as_ref() else {
            self.loaded = true;
            return Ok(());
        };
        let Some(sql) = self.sql.as_ref() else {
            self.loaded = true;
            return Ok(());
        };

        let mut statement = connection.prepare(sql).map_err(php_error)?;
        let column_count = self.columns.len();
        let mut rows = statement.query([]).map_err(php_error)?;
        while let Some(row) = rows.next().map_err(php_error)? {
            let mut values = Vec::with_capacity(column_count);
            for index in 0..column_count {
                values.push(NativeSqliteValue::from_value_ref(
                    row.get_ref(index).map_err(php_error)?,
                ));
            }
            self.rows.push(values);
        }
        self.loaded = true;
        Ok(())
    }

    fn fetch_all_from_sql(&mut self, mode: i64, column: usize) -> PhpResult<Zval> {
        let Some(connection) = self.connection.as_ref() else {
            self.loaded = true;
            self.exhausted = true;
            return ZendHashTable::new().into_zval(false).map_err(php_error);
        };
        let Some(sql) = self.sql.as_ref() else {
            self.loaded = true;
            self.exhausted = true;
            return ZendHashTable::new().into_zval(false).map_err(php_error);
        };

        let mut statement = connection.prepare(sql).map_err(php_error)?;
        let column_count = self.columns.len();
        let mut rows = statement.query([]).map_err(php_error)?;
        let mut result = ZendHashTable::new();
        while let Some(row) = rows.next().map_err(php_error)? {
            let mut values = Vec::with_capacity(column_count);
            for index in 0..column_count {
                values.push(NativeSqliteValue::from_value_ref(
                    row.get_ref(index).map_err(php_error)?,
                ));
            }
            let row = if mode == FETCH_COLUMN {
                values
                    .get(column)
                    .map(NativeSqliteValue::to_zval)
                    .unwrap_or_else(|| false.into_zval(false).map_err(php_error))?
            } else {
                self.row_to_zval(&values, mode, 0)?
            };
            result.push(row).map_err(php_error)?;
        }

        self.loaded = true;
        self.exhausted = true;
        result.into_zval(false).map_err(php_error)
    }

    fn resolve_fetch_mode(&self, mode: i64) -> i64 {
        if mode == FETCH_DEFAULT {
            self.default_fetch_mode
        } else {
            mode
        }
    }

    fn row_to_zval(&self, row: &[NativeSqliteValue], mode: i64, column: usize) -> PhpResult<Zval> {
        match mode {
            FETCH_ASSOC => self.row_to_assoc(row, false),
            FETCH_NUM => self.row_to_num(row),
            FETCH_OBJ => self.row_to_object(row),
            FETCH_COLUMN => row
                .get(column)
                .map(NativeSqliteValue::to_zval)
                .unwrap_or_else(|| false.into_zval(false).map_err(php_error)),
            FETCH_NAMED => self.row_to_assoc(row, true),
            _ => self.row_to_both(row),
        }
    }

    fn row_to_num(&self, row: &[NativeSqliteValue]) -> PhpResult<Zval> {
        let mut array = ZendHashTable::with_capacity(row.len().try_into().unwrap_or(0));
        for value in row {
            array.push(value.to_zval()?).map_err(php_error)?;
        }
        array.into_zval(false).map_err(php_error)
    }

    fn row_to_assoc(&self, row: &[NativeSqliteValue], named_duplicates: bool) -> PhpResult<Zval> {
        let mut array = ZendHashTable::with_capacity(row.len().try_into().unwrap_or(0));
        for (index, value) in row.iter().enumerate() {
            let name = &self.columns[index];
            if named_duplicates && array.get(name.as_str()).is_some() {
                let mut values = ZendHashTable::new();
                values
                    .push(array.get(name.as_str()).unwrap().shallow_clone())
                    .map_err(php_error)?;
                values.push(value.to_zval()?).map_err(php_error)?;
                array.insert(name.as_str(), values).map_err(php_error)?;
            } else {
                array
                    .insert(name.as_str(), value.to_zval()?)
                    .map_err(php_error)?;
            }
        }
        array.into_zval(false).map_err(php_error)
    }

    fn row_to_both(&self, row: &[NativeSqliteValue]) -> PhpResult<Zval> {
        let mut array = ZendHashTable::with_capacity((row.len() * 2).try_into().unwrap_or(0));
        for value in row {
            array.push(value.to_zval()?).map_err(php_error)?;
        }
        for (index, value) in row.iter().enumerate() {
            array
                .insert(self.columns[index].as_str(), value.to_zval()?)
                .map_err(php_error)?;
        }
        array.into_zval(false).map_err(php_error)
    }

    fn row_to_object(&self, row: &[NativeSqliteValue]) -> PhpResult<Zval> {
        let mut object = ZendObject::new_stdclass();
        for (index, value) in row.iter().enumerate() {
            object
                .set_property(self.columns[index].as_str(), value.to_zval()?)
                .map_err(php_error)?;
        }
        object.into_zval(false).map_err(php_error)
    }
}
