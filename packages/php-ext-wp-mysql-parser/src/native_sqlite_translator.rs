pub(crate) const SELECT_PASSTHROUGH: &str = "select_passthrough";
pub(crate) const UPDATE_PASSTHROUGH: &str = "update_passthrough";
const SELECT_FOUND_ROWS: &str = "select_found_rows";
const SELECT_SESSION_SQL_MODE: &str = "select_session_sql_mode";
const SET_SESSION_SQL_MODE: &str = "set_session_sql_mode";

const PLAN_UNSUPPORTED: i64 = 0;
pub(crate) const PLAN_SELECT_ORIGINAL: i64 = 1;
pub(crate) const PLAN_UPDATE_ORIGINAL: i64 = 2;
pub(crate) const PLAN_SELECT_FOUND_ROWS_CODE: i64 = 3;

const ALLOWED_TABLE_SUFFIXES: &[&str] = &[
    "actionscheduler_actions",
    "actionscheduler_claims",
    "actionscheduler_groups",
    "actionscheduler_logs",
    "blogmeta",
    "blogs",
    "commentmeta",
    "comments",
    "links",
    "options",
    "postmeta",
    "posts",
    "registration_log",
    "signups",
    "site",
    "sitemeta",
    "term_relationships",
    "term_taxonomy",
    "termmeta",
    "terms",
    "usermeta",
    "users",
    "wc_admin_note_actions",
    "wc_admin_notes",
    "wc_category_lookup",
    "wc_customer_lookup",
    "wc_download_log",
    "wc_order_addresses",
    "wc_order_coupon_lookup",
    "wc_order_operational_data",
    "wc_order_product_lookup",
    "wc_order_stats",
    "wc_order_tax_lookup",
    "wc_orders",
    "wc_orders_meta",
    "wc_product_attributes_lookup",
    "wc_product_download_directories",
    "wc_product_meta_lookup",
    "wc_rate_limits",
    "wc_reserved_stock",
    "wc_tax_rate_classes",
    "wc_webhooks",
    "woocommerce_api_keys",
    "woocommerce_attribute_taxonomies",
    "woocommerce_downloadable_product_permissions",
    "woocommerce_log",
    "woocommerce_order_itemmeta",
    "woocommerce_order_items",
    "woocommerce_payment_tokenmeta",
    "woocommerce_payment_tokens",
    "woocommerce_sessions",
    "woocommerce_shipping_zone_locations",
    "woocommerce_shipping_zone_methods",
    "woocommerce_shipping_zones",
    "woocommerce_tax_rate_locations",
    "woocommerce_tax_rates",
];

pub fn translate_sqlite_plan(sql: &[u8]) -> Option<Vec<String>> {
    let sql = std::str::from_utf8(sql).ok()?;
    let sql = trim_sql(sql);
    if sql.is_empty() {
        return None;
    }

    if let Some(modes) = parse_set_session_sql_mode(sql) {
        return Some(vec![SET_SESSION_SQL_MODE.to_string(), modes]);
    }

    if let Some(alias) = parse_select_session_sql_mode(sql) {
        return Some(vec![SELECT_SESSION_SQL_MODE.to_string(), alias]);
    }

    if is_select_found_rows(sql) {
        return Some(vec![SELECT_FOUND_ROWS.to_string()]);
    }

    if is_fast_update_passthrough_candidate(sql) {
        return Some(vec![UPDATE_PASSTHROUGH.to_string(), sql.to_string()]);
    }

    if !is_fast_select_passthrough_candidate(sql) {
        return None;
    }

    let (sqlite_query, count_query) = strip_sql_calc_found_rows(sql);
    Some(vec![
        SELECT_PASSTHROUGH.to_string(),
        sqlite_query,
        count_query.unwrap_or_default().to_string(),
    ])
}

pub fn translate_sqlite_plan_code(sql: &[u8]) -> i64 {
    let Ok(original_sql) = std::str::from_utf8(sql) else {
        return PLAN_UNSUPPORTED;
    };
    let sql = trim_sql(original_sql);
    if sql.is_empty() {
        return PLAN_UNSUPPORTED;
    }

    if is_select_found_rows(sql) {
        return PLAN_SELECT_FOUND_ROWS_CODE;
    }

    if is_fast_update_passthrough_candidate(sql) {
        if sql.len() != original_sql.len() {
            return PLAN_UNSUPPORTED;
        }
        return PLAN_UPDATE_ORIGINAL;
    }

    if !is_fast_select_passthrough_candidate(sql) {
        return PLAN_UNSUPPORTED;
    }

    if contains_sql_calc_found_rows(sql) {
        return PLAN_UNSUPPORTED;
    }

    if sql.len() != original_sql.len() {
        return PLAN_UNSUPPORTED;
    }

    PLAN_SELECT_ORIGINAL
}

fn trim_sql(sql: &str) -> &str {
    sql.trim().trim_end_matches(';').trim_end()
}

fn parse_set_session_sql_mode(sql: &str) -> Option<String> {
    let mut rest = strip_keyword(sql, "set")?;
    rest = rest.trim_start();
    rest = strip_keyword(rest, "session")?;
    rest = rest.trim_start();
    rest = strip_keyword(rest, "sql_mode")?;

    let mut original_rest = rest.trim_start();
    original_rest = original_rest.strip_prefix('=')?;
    original_rest = original_rest.trim_start();

    let mut chars = original_rest.chars();
    let quote = chars.next()?;
    if quote != '\'' && quote != '"' {
        return None;
    }
    let end = original_rest[1..].find(quote)? + 1;
    if !original_rest[end + 1..].trim().is_empty() {
        return None;
    }
    let modes = &original_rest[1..end];
    Some(
        modes
            .split(',')
            .map(|mode| mode.trim().to_ascii_uppercase())
            .filter(|mode| !mode.is_empty())
            .collect::<Vec<_>>()
            .join(","),
    )
}

fn parse_select_session_sql_mode(sql: &str) -> Option<String> {
    let sql = sql.trim();
    if !sql.eq_ignore_ascii_case("select @@session.sql_mode") {
        return None;
    }
    Some(sql["select".len()..].trim().to_string())
}

fn is_select_found_rows(sql: &str) -> bool {
    let target = b"selectfound_rows()";
    let mut index = 0;
    for byte in sql.bytes() {
        if byte.is_ascii_whitespace() {
            continue;
        }
        if target
            .get(index)
            .is_none_or(|expected| byte.to_ascii_lowercase() != *expected)
        {
            return false;
        }
        index += 1;
    }
    index == target.len()
}

fn strip_sql_calc_found_rows(sql: &str) -> (String, Option<String>) {
    let Some(after_select) = strip_keyword(sql, "select") else {
        return (sql.to_string(), None);
    };
    let after_select = after_select.trim_start();
    let Some(after_sql_calc_found_rows) = strip_keyword(after_select, "sql_calc_found_rows") else {
        return (sql.to_string(), None);
    };

    let sqlite_query = format!("SELECT {}", after_sql_calc_found_rows.trim_start());
    let count_query = strip_trailing_limit(&sqlite_query)
        .unwrap_or(&sqlite_query)
        .to_string();
    (sqlite_query, Some(count_query))
}

fn strip_trailing_limit(sql: &str) -> Option<&str> {
    let lower = sql.to_ascii_lowercase();
    let index = lower.rfind(" limit ")?;
    let tail = sql[index + " limit ".len()..].trim();
    if !is_simple_limit_tail(tail) {
        return None;
    }
    Some(sql[..index].trim_end())
}

fn is_simple_limit_tail(tail: &str) -> bool {
    let lower = tail.to_ascii_lowercase();
    let parts = lower.split_whitespace().collect::<Vec<_>>();
    match parts.as_slice() {
        [count] => is_digits(count),
        [offset, count] => {
            offset.ends_with(',') && is_digits(offset.trim_end_matches(',')) && is_digits(count)
        }
        [count, "offset", offset] => is_digits(count) && is_digits(offset),
        _ => false,
    }
}

fn is_fast_select_passthrough_candidate(sql: &str) -> bool {
    if strip_keyword(sql, "select").is_none() {
        return false;
    }

    let lower = sql.to_ascii_lowercase();
    if !lower.contains(" from ") {
        return false;
    }

    if lower.contains("information_schema") || lower.contains("_wp_sqlite_") {
        return false;
    }

    if !contains_allowed_table_after_keyword(sql, &lower, &["from", "join"]) {
        return false;
    }

    if has_common_passthrough_hazard(sql, &lower) {
        return false;
    }

    has_no_banned_select_construct(&lower)
}

fn is_fast_update_passthrough_candidate(sql: &str) -> bool {
    if strip_keyword(sql, "update").is_none() {
        return false;
    }

    let lower = sql.to_ascii_lowercase();
    if !contains_allowed_table_after_keyword(sql, &lower, &["update"]) {
        return false;
    }

    if !lower.contains(" set ") {
        return false;
    }

    if has_common_passthrough_hazard(sql, &lower) {
        return false;
    }

    !contains_any_ci(
        &lower,
        &[
            " low_priority ",
            " ignore ",
            " join ",
            " order by ",
            " limit ",
            " match ",
            " against ",
            " collate ",
            " interval ",
            " rlike ",
            " binary ",
        ],
    ) && has_no_banned_function(&lower)
        && !contains_cast_as_signed(&lower)
}

fn has_common_passthrough_hazard(sql: &str, lower: &str) -> bool {
    sql.contains(';')
        || sql.contains('\\')
        || sql.contains('@')
        || sql.contains("--")
        || sql.contains("/*")
        || sql.contains('#')
        || sql.contains("->")
        || contains_hex_literal(&lower)
        || contains_charset_introducer(&lower)
}

fn has_no_banned_select_construct(lower: &str) -> bool {
    !contains_any_ci(
        lower,
        &[
            " union ",
            " intersect ",
            " except ",
            " match ",
            " against ",
            " collate ",
            " interval ",
            " rlike ",
            " binary ",
            " for update",
            " lock in share mode",
            " use index",
            " use key",
            " force index",
            " force key",
            " ignore index",
            " ignore key",
        ],
    ) && has_no_banned_function(lower)
        && !contains_cast_as_signed(lower)
        && (!lower.contains("sql_calc_found_rows") || strip_sql_calc_found_rows(lower).1.is_some())
}

fn has_no_banned_function(lower: &str) -> bool {
    ![
        "if",
        "concat",
        "char_length",
        "date_add",
        "date_sub",
        "date_format",
        "str_to_date",
        "rand",
        "regexp",
        "found_rows",
        "database",
        "version",
        "last_insert_id",
        "row_count",
        "group_concat",
    ]
    .iter()
    .any(|name| contains_function_call(lower, name))
}

fn contains_allowed_table_after_keyword(sql: &str, lower: &str, keywords: &[&str]) -> bool {
    for keyword in keywords {
        let mut search_from = 0;
        while let Some(relative_index) = lower[search_from..].find(keyword) {
            let index = search_from + relative_index;
            let before_ok = index == 0
                || !lower.as_bytes()[index - 1].is_ascii_alphanumeric()
                    && lower.as_bytes()[index - 1] != b'_';
            let after_index = index + keyword.len();
            let after_ok = after_index >= lower.len()
                || !lower.as_bytes()[after_index].is_ascii_alphanumeric()
                    && lower.as_bytes()[after_index] != b'_';
            if before_ok && after_ok {
                let rest = &sql[after_index..];
                if let Some(table) = first_identifier(rest) {
                    if is_allowed_table_name(table) {
                        return true;
                    }
                }
            }
            search_from = after_index;
        }
    }
    false
}

fn first_identifier(input: &str) -> Option<&str> {
    let input = input.trim_start();
    let end = input
        .find(|ch: char| ch.is_ascii_whitespace() || ch == ',' || ch == '(')
        .unwrap_or(input.len());
    if end == 0 {
        return None;
    }
    Some(&input[..end])
}

fn is_allowed_table_name(table: &str) -> bool {
    let table = table
        .trim_matches('`')
        .rsplit('.')
        .next()
        .unwrap_or(table)
        .trim_matches('`')
        .to_ascii_lowercase();
    ALLOWED_TABLE_SUFFIXES.iter().any(|suffix| {
        table == *suffix
            || table
                .strip_suffix(suffix)
                .is_some_and(|prefix| prefix.ends_with('_'))
    })
}

fn contains_any_ci(lower: &str, needles: &[&str]) -> bool {
    needles.iter().any(|needle| lower.contains(needle))
}

fn strip_keyword<'a>(s: &'a str, keyword: &str) -> Option<&'a str> {
    let head = s.get(..keyword.len())?;
    if !head.eq_ignore_ascii_case(keyword) {
        return None;
    }

    let rest = &s[keyword.len()..];
    if rest
        .as_bytes()
        .first()
        .is_some_and(|byte| byte.is_ascii_alphanumeric() || *byte == b'_')
    {
        return None;
    }
    Some(rest)
}

fn is_digits(s: &str) -> bool {
    !s.is_empty() && s.bytes().all(|b| b.is_ascii_digit())
}

fn contains_function_call(lower: &str, name: &str) -> bool {
    let bytes = lower.as_bytes();
    let mut search_from = 0;
    while let Some(relative_index) = lower[search_from..].find(name) {
        let index = search_from + relative_index;
        let before_ok = index == 0 || !is_identifier_byte(bytes[index - 1]);
        let mut after_index = index + name.len();
        while bytes.get(after_index).is_some_and(u8::is_ascii_whitespace) {
            after_index += 1;
        }
        if before_ok && bytes.get(after_index) == Some(&b'(') {
            return true;
        }
        search_from = index + name.len();
    }
    false
}

fn contains_cast_as_signed(lower: &str) -> bool {
    contains_function_call(lower, "cast")
        && (lower.contains(" as unsigned") || lower.contains(" as signed"))
}

pub(crate) fn contains_sql_calc_found_rows(sql: &str) -> bool {
    const NEEDLE: &[u8] = b"sql_calc_found_rows";
    sql.as_bytes()
        .windows(NEEDLE.len())
        .any(|window| window.eq_ignore_ascii_case(NEEDLE))
}

fn is_identifier_byte(byte: u8) -> bool {
    byte.is_ascii_alphanumeric() || byte == b'_'
}

fn contains_hex_literal(lower: &str) -> bool {
    lower.as_bytes().windows(2).enumerate().any(|(i, pair)| {
        pair == b"0x"
            && lower
                .as_bytes()
                .get(i + 2)
                .is_some_and(u8::is_ascii_hexdigit)
    })
}

fn contains_charset_introducer(lower: &str) -> bool {
    ["_utf8", "_utf8mb4", "_latin1", "_binary"]
        .iter()
        .any(|needle| {
            let mut search_from = 0;
            while let Some(relative_index) = lower[search_from..].find(needle) {
                let after_needle = search_from + relative_index + needle.len();
                let tail = lower[after_needle..].trim_start();
                if tail.starts_with('\'') {
                    return true;
                }
                search_from = after_needle;
            }
            false
        })
}

#[cfg(test)]
mod tests {
    use super::{translate_sqlite_plan, translate_sqlite_plan_code};

    #[test]
    fn translates_sql_calc_found_rows_select() {
        assert_eq!(
            translate_sqlite_plan(
                b"SELECT SQL_CALC_FOUND_ROWS ID FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT 0, 2",
            ),
            Some(vec![
                "select_passthrough".to_string(),
                "SELECT ID FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT 0, 2"
                    .to_string(),
                "SELECT ID FROM wp_posts WHERE post_status = 'publish' ORDER BY ID".to_string(),
            ])
        );
        assert_eq!(
            translate_sqlite_plan_code(
                b"SELECT SQL_CALC_FOUND_ROWS ID FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT 0, 2",
            ),
            0
        );
    }

    #[test]
    fn translates_update_passthrough() {
        assert_eq!(
            translate_sqlite_plan(
                b"UPDATE `wp_options` SET `option_value` = '1' WHERE `option_name` = 'x'",
            ),
            Some(vec![
                "update_passthrough".to_string(),
                "UPDATE `wp_options` SET `option_value` = '1' WHERE `option_name` = 'x'"
                    .to_string(),
            ])
        );
        assert_eq!(
            translate_sqlite_plan_code(
                b"UPDATE `wp_options` SET `option_value` = '1' WHERE `option_name` = 'x'",
            ),
            2
        );
    }

    #[test]
    fn translates_plain_select_to_original_query_code() {
        assert_eq!(
            translate_sqlite_plan_code(b"SELECT ID FROM wp_posts WHERE post_status = 'publish'"),
            1
        );
    }

    #[test]
    fn translates_session_sql_mode_queries() {
        assert_eq!(
            translate_sqlite_plan(b"SET SESSION sql_mode = 'NO_ZERO_DATE, strict_trans_tables'"),
            Some(vec![
                "set_session_sql_mode".to_string(),
                "NO_ZERO_DATE,STRICT_TRANS_TABLES".to_string(),
            ])
        );
        assert_eq!(
            translate_sqlite_plan(b"SELECT @@session.SQL_mode"),
            Some(vec![
                "select_session_sql_mode".to_string(),
                "@@session.SQL_mode".to_string(),
            ])
        );
    }

    #[test]
    fn rejects_queries_that_need_mysql_translation() {
        assert_eq!(
            translate_sqlite_plan(b"SELECT * FROM information_schema.tables"),
            None
        );
        assert_eq!(
            translate_sqlite_plan_code(b"SELECT * FROM information_schema.tables"),
            0
        );
        assert_eq!(
            translate_sqlite_plan(b"SELECT CAST (meta_value AS UNSIGNED) FROM wp_postmeta"),
            None
        );
        assert_eq!(
            translate_sqlite_plan(b"SELECT _utf8 'x' FROM wp_posts"),
            None
        );
    }
}
