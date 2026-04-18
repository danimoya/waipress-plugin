<?php
/**
 * WAIpress Database Drop-in for HeliosDB-Nano
 * Auto-increment simulation + SQL translation + alias.* expansion
 */

class WAIpress_Nano_DB extends wpdb {

    private $auto_increment_tables = array();

    public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
        // Build auto-increment map using the configured table prefix
        global $table_prefix;
        $pfx = $table_prefix ?: 'wp_';

        $this->auto_increment_tables = array(
            // WordPress core tables
            $pfx . 'posts'           => 'ID',
            $pfx . 'users'           => 'ID',
            $pfx . 'comments'        => 'comment_ID',
            $pfx . 'links'           => 'link_id',
            $pfx . 'options'         => 'option_id',
            $pfx . 'postmeta'        => 'meta_id',
            $pfx . 'usermeta'        => 'umeta_id',
            $pfx . 'commentmeta'     => 'meta_id',
            $pfx . 'terms'           => 'term_id',
            $pfx . 'term_taxonomy'   => 'term_taxonomy_id',
            $pfx . 'termmeta'        => 'meta_id',
            // WAIpress custom tables
            $pfx . 'wai_channels'           => 'id',
            $pfx . 'wai_conversations'      => 'id',
            $pfx . 'wai_messages'           => 'id',
            $pfx . 'wai_conversation_participants' => 'id',
            $pfx . 'wai_contacts'           => 'id',
            $pfx . 'wai_contact_segments'   => 'id',
            $pfx . 'wai_deal_stages'        => 'id',
            $pfx . 'wai_deals'              => 'id',
            $pfx . 'wai_activities'         => 'id',
            $pfx . 'wai_chatbot_configs'    => 'id',
            $pfx . 'wai_chatbot_sessions'   => 'id',
            $pfx . 'wai_chatbot_messages'   => 'id',
            $pfx . 'wai_ai_prompts'         => 'id',
            $pfx . 'wai_ai_generations'     => 'id',
            $pfx . 'wai_ai_usage_logs'      => 'id',
            $pfx . 'wai_embeddings'         => 'id',
            $pfx . 'wai_products'           => 'id',
            $pfx . 'wai_product_variants'   => 'id',
            $pfx . 'wai_orders'             => 'id',
            $pfx . 'wai_order_items'        => 'id',
            $pfx . 'wai_carts'              => 'id',
            $pfx . 'wai_coupons'            => 'id',
            $pfx . 'wai_inventory'          => 'id',
        );

        parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
    }

    private $nano_insert_id = 0;

    /** Cache of table => [column_name, ...] */
    private $table_columns_cache = array();

    /**
     * Get column names for a table (cached).
     */
    private function get_table_columns( $table ) {
        $table = strtolower( $table );
        if ( isset( $this->table_columns_cache[$table] ) ) {
            return $this->table_columns_cache[$table];
        }
        if ( empty( $this->dbh ) ) {
            return array();
        }
        $r = @mysqli_query( $this->dbh, "SHOW COLUMNS FROM $table" );
        if ( ! $r ) {
            return array();
        }
        $cols = array();
        while ( $row = mysqli_fetch_assoc( $r ) ) {
            $cols[] = $row['column_name'];
        }
        $this->table_columns_cache[$table] = $cols;
        return $cols;
    }

    /**
     * Expand alias.* in SELECT queries to explicit column lists.
     * Nano doesn't support SELECT t.* with table aliases.
     */
    private function expand_alias_star( $query ) {
        // Only process if there's an alias.* pattern
        if ( ! preg_match( '/\b\w+\.\*/', $query ) ) {
            return $query;
        }

        // Extract table aliases from FROM and JOIN clauses
        $aliases = array();
        // Match: FROM table AS alias, FROM table alias, JOIN table AS alias, JOIN table alias
        if ( preg_match_all( '/(?:FROM|JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $query, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $table = $m[1];
                $alias = isset( $m[2] ) && $m[2] !== '' ? $m[2] : $table;
                // Skip SQL keywords that might be mismatched
                if ( in_array( strtoupper( $alias ), array( 'INNER', 'LEFT', 'RIGHT', 'OUTER', 'CROSS', 'ON', 'WHERE', 'SET', 'JOIN' ) ) ) {
                    $alias = $table;
                }
                $aliases[strtolower( $alias )] = strtolower( $table );
            }
        }

        if ( empty( $aliases ) ) {
            return $query;
        }

        // Find all alias.* patterns and expand them
        $query = preg_replace_callback( '/\b(\w+)\.\*/', function( $m ) use ( $aliases ) {
            $alias = strtolower( $m[1] );
            if ( ! isset( $aliases[$alias] ) ) {
                return $m[0]; // Unknown alias, leave as-is
            }
            $table = $aliases[$alias];
            $cols = $this->get_table_columns( $table );
            if ( empty( $cols ) ) {
                return $m[0]; // Can't resolve columns
            }
            // Build: alias.col1, alias.col2, ...
            $expanded = array();
            foreach ( $cols as $col ) {
                $expanded[] = $m[1] . '.' . $col;
            }
            return implode( ', ', $expanded );
        }, $query );

        return $query;
    }

    public function query( $query ) {
        if ( ! $this->ready ) {
            $this->check_current_query = true;
            return false;
        }

        $query = apply_filters( 'query', $query );

        if ( ! $query ) {
            $this->insert_id = 0;
            return false;
        }

        $this->flush();

        $this->func_call = "\$db->query(\"$query\")";

        if ( $this->check_current_query && ! $this->check_ascii( $query ) ) {
            $stripped_query = $this->strip_invalid_text_from_query( $query );
            $this->flush();
            if ( $stripped_query !== $query ) {
                $this->insert_id  = 0;
                $this->last_query = $query;
                wp_load_translations_early();
                $this->last_error = __( 'WordPress database error: Could not perform query because it contains invalid data.' );
                return false;
            }
        }

        $this->check_current_query = true;
        $this->last_query = $query;
        $this->nano_insert_id = 0;

        $this->_do_query( $query );

        $mysql_errno = 0;
        if ( $this->dbh instanceof mysqli ) {
            $mysql_errno = mysqli_errno( $this->dbh );
        } else {
            $mysql_errno = 2006;
        }

        if ( empty( $this->dbh ) || 2006 === $mysql_errno ) {
            if ( $this->check_connection() ) {
                $this->_do_query( $query );
            } else {
                $this->insert_id = 0;
                return false;
            }
        }

        if ( $this->dbh instanceof mysqli ) {
            $this->last_error = mysqli_error( $this->dbh );
        }

        if ( $this->last_error ) {
            if ( $this->insert_id && preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
                $this->insert_id = 0;
            }
            $this->print_error();
            return false;
        }

        if ( preg_match( '/^\s*(create|alter|truncate|drop)\s/i', $query ) ) {
            $return_val = $this->result;
        } elseif ( preg_match( '/^\s*(insert|delete|update|replace)\s/i', $query ) ) {
            if ( $this->dbh instanceof mysqli ) {
                $this->rows_affected = mysqli_affected_rows( $this->dbh );
            }
            if ( preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
                if ( $this->nano_insert_id > 0 ) {
                    $this->insert_id = $this->nano_insert_id;
                } else {
                    $this->insert_id = mysqli_insert_id( $this->dbh );
                }
            }
            $return_val = $this->rows_affected;
        } else {
            $num_rows = 0;
            if ( $this->result instanceof mysqli_result ) {
                while ( $row = mysqli_fetch_object( $this->result ) ) {
                    $this->last_result[ $num_rows ] = $row;
                    ++$num_rows;
                }
            }
            $this->num_rows = $num_rows;
            $return_val     = $num_rows;
        }

        return $return_val;
    }

    public function _do_query( $query ) {
        if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
            $this->timer_start();
        }

        $query_trimmed = trim( $query );
        $query_upper = strtoupper( $query_trimmed );

        // SET commands -> no-op
        if ( preg_match( '/^SET\s+(NAMES|CHARACTER|character_set|@@|wait_timeout|net_read|SQL_BIG_SELECTS)/i', $query_trimmed ) ) {
            $this->result = true;
            return;
        }

        // SELECT @@ session/global variables -> fake
        if ( strpos( $query_upper, 'SELECT @@' ) !== false ) {
            if ( strpos( $query_upper, '@@SESSION' ) !== false || strpos( $query_upper, '@@GLOBAL' ) !== false ) {
                $this->result = true;
                return;
            }
        }

        // Strip backticks
        $query = str_replace( '`', '', $query );

        // Expand alias.* patterns (must be before execution)
        $query = $this->expand_alias_star( $query );

        // ON DUPLICATE KEY UPDATE -> strip
        if ( stripos( $query, 'ON DUPLICATE KEY UPDATE' ) !== false ) {
            $query = preg_replace( '/\s+ON DUPLICATE KEY UPDATE.*/is', '', $query );
        }

        // CREATE TABLE translation
        if ( stripos( $query, 'CREATE TABLE' ) !== false ) {
            $query = $this->translate_create_table( $query );
        }

        // ALTER TABLE -> suppress errors
        if ( stripos( $query, 'ALTER TABLE' ) !== false ) {
            if ( ! empty( $this->dbh ) ) {
                $this->result = @mysqli_query( $this->dbh, $query );
                if ( ! $this->result ) {
                    $this->result = true;
                }
                return;
            }
        }

        // DESCRIBE -> no-op
        if ( preg_match( '/^(DESCRIBE|DESC)\s+/i', trim( $query ) ) ) {
            $this->result = true;
            return;
        }

        // SHOW INDEX/KEYS -> no-op
        if ( preg_match( '/^SHOW\s+(INDEX|KEYS)\s+/i', trim( $query ) ) ) {
            $this->result = true;
            return;
        }

        // Auto-increment simulation for INSERT
        if ( preg_match( '/^INSERT\s+INTO\s+(\w+)\s*\(/i', trim( $query ), $match ) ) {
            $table = strtolower( $match[1] );
            if ( isset( $this->auto_increment_tables[$table] ) && ! empty( $this->dbh ) ) {
                $pk = $this->auto_increment_tables[$table];
                
                preg_match( '/^INSERT\s+INTO\s+\w+\s*\(([^)]+)\)/i', $query, $cols_match );
                if ( $cols_match ) {
                    $cols = array_map( 'trim', explode( ',', $cols_match[1] ) );
                    $has_pk = false;
                    $pk_index = -1;
                    foreach ( $cols as $i => $col ) {
                        if ( strcasecmp( $col, $pk ) === 0 ) {
                            $has_pk = true;
                            $pk_index = $i;
                            break;
                        }
                    }

                    $next_id = 1;
                    $max_r = @mysqli_query( $this->dbh, "SELECT MAX($pk) as max_id FROM $table" );
                    if ( $max_r && $max_row = mysqli_fetch_assoc( $max_r ) ) {
                        $next_id = intval( $max_row['max_id'] ) + 1;
                    }

                    if ( ! $has_pk ) {
                        $query = preg_replace(
                            '/^(INSERT\s+INTO\s+\w+\s*\()/i',
                            '${1}' . $pk . ', ',
                            $query
                        );
                        $query = preg_replace(
                            '/VALUES\s*\(/i',
                            'VALUES (' . $next_id . ', ',
                            $query
                        );
                    } else {
                        preg_match( '/VALUES\s*\((.+)\)/is', $query, $vals_match );
                        if ( $vals_match ) {
                            $vals = $this->split_sql_values( $vals_match[1] );
                            if ( isset( $vals[$pk_index] ) ) {
                                $pk_val = trim( $vals[$pk_index], " '\"\t\n\r" );
                                if ( $pk_val === '' || $pk_val === '0' || strtoupper($pk_val) === 'NULL' ) {
                                    $vals[$pk_index] = (string)$next_id;
                                    $new_values = implode( ', ', $vals );
                                    $query = preg_replace(
                                        '/VALUES\s*\(.+\)/is',
                                        'VALUES (' . $new_values . ')',
                                        $query
                                    );
                                } else {
                                    $next_id = intval( $pk_val );
                                }
                            }
                        }
                    }

                    $this->result = @mysqli_query( $this->dbh, $query );
                    if ( $this->result ) {
                        $this->insert_id = $next_id;
                        $this->nano_insert_id = $next_id;
                    }

                    if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
                        $this->time_start = null;
                    }
                    return;
                }
            }
        }

        // Execute via real mysqli
        if ( ! empty( $this->dbh ) ) {
            $this->result = @mysqli_query( $this->dbh, $query );
        }

        if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
            $this->time_start = null;
        }
    }

    public function get_table_charset( $table ) {
        return array( 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci' );
    }

    public function get_col_charset( $table, $column ) {
        return 'utf8mb4';
    }

    protected function strip_invalid_text( $data ) {
        return $data;
    }

    public function strip_invalid_text_for_column( $table, $column, $value ) {
        return $value;
    }

    private function split_sql_values( $str ) {
        $values = array();
        $current = '';
        $in_quote = false;
        $quote_char = '';
        $escaped = false;
        $len = strlen( $str );
        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $str[$i];
            if ( $escaped ) {
                $current .= $ch;
                $escaped = false;
                continue;
            }
            if ( $ch === '\\' ) {
                $current .= $ch;
                $escaped = true;
                continue;
            }
            if ( ! $in_quote && ( $ch === "'" || $ch === '"' ) ) {
                $in_quote = true;
                $quote_char = $ch;
                $current .= $ch;
                continue;
            }
            if ( $in_quote && $ch === $quote_char ) {
                $in_quote = false;
                $current .= $ch;
                continue;
            }
            if ( ! $in_quote && $ch === ',' ) {
                $values[] = trim( $current );
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if ( $current !== '' ) {
            $values[] = trim( $current );
        }
        return $values;
    }

    private function translate_create_table( $sql ) {
        $sql = preg_replace( '/\bbigint\(\d+\)\s+unsigned\s+NOT\s+NULL\s+auto_increment/i', 'INTEGER NOT NULL', $sql );
        $sql = preg_replace( '/\bint\(\d+\)\s+unsigned\s+NOT\s+NULL\s+auto_increment/i', 'INTEGER NOT NULL', $sql );
        $sql = preg_replace( '/\bbigint\(\d+\)\s+NOT\s+NULL\s+auto_increment/i', 'INTEGER NOT NULL', $sql );
        $sql = preg_replace( '/\bint\(\d+\)\s+NOT\s+NULL\s+auto_increment/i', 'INTEGER NOT NULL', $sql );
        $sql = preg_replace( '/\s+auto_increment/i', '', $sql );
        $sql = preg_replace( '/\s*(ENGINE|DEFAULT\s+CHARSET|COLLATE|AUTO_INCREMENT|ROW_FORMAT)\s*=?\s*\S+/i', '', $sql );
        $sql = preg_replace( '/\s+DEFAULT\s+CHARACTER\s+SET\s+\S+(\s+COLLATE\s+\S+)?/i', '', $sql );
        $sql = preg_replace( '/\bUNSIGNED\b/i', '', $sql );
        $sql = preg_replace( '/\bbigint\(\d+\)/i', 'BIGINT', $sql );
        $sql = preg_replace( '/\bint\(\d+\)/i', 'INT', $sql );
        $sql = preg_replace( '/\btinyint\(\d+\)/i', 'SMALLINT', $sql );
        $sql = preg_replace( '/\bLONGTEXT\b/i', 'TEXT', $sql );
        $sql = preg_replace( '/\bMEDIUMTEXT\b/i', 'TEXT', $sql );
        $sql = preg_replace( '/\bvarchar\(\d+\)/i', 'TEXT', $sql );
        $sql = preg_replace( '/\bDATETIME\b/i', 'TEXT', $sql );
        $sql = preg_replace( '/,\s*UNIQUE\s+KEY\s+\w+\s*\([^)]*(?:\([^)]*\)[^)]*)*\)/i', '', $sql );
        $sql = preg_replace( '/,\s*KEY\s+\w+\s*\([^)]*(?:\([^)]*\)[^)]*)*\)/i', '', $sql );
        $sql = preg_replace( '/\bIF\s+NOT\s+EXISTS\b/i', '', $sql );
        return $sql;
    }

    public function _real_escape( $string ) {
        if ( $this->dbh instanceof mysqli ) {
            $escaped = mysqli_real_escape_string( $this->dbh, $string );
            $escaped = str_replace( '\\"', '"', $escaped );
            return $escaped;
        }
        return addslashes( $string );
    }

    public function set_sql_mode( $modes = array() ) { return; }
    public function select( $db, $dbh = null ) { return true; }
    public function db_version() { return '8.0.35'; }

    public function check_connection( $allow_bail = true ) {
        if ( $this->dbh instanceof mysqli && @mysqli_ping( $this->dbh ) ) {
            return true;
        }
        return $this->db_connect( $allow_bail );
    }
}

$wpdb = new WAIpress_Nano_DB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
