<?php
$mysqli_link = null;

function connect() {
  // Store the mysql connection as a link to reference.
  $GLOBALS['mysqli_link'] = mysqli_connect(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

  // Check the connection for error and die on error.
  if (mysqli_connect_errno()) {
    die(mysqli_connect_errno());
  }
}

// Dispatch to a phtml page or to a class method or die
function dispatch($site_class) {
    $in_path = $_REQUEST['path'];
    $path = array_map("pacify_path", explode("/", $in_path));
    if (!$path[0]) $method = "home";
    else $method = $path[0];
    $params = count($path) == 1 ? null : array_slice($path, 1);

    // .phtml page?
    if (file_exists($method . ".phtml")) {
        include($method . ".phtml");
        return;
    }

    // Special method suffixes for defined resources:
    //      foobars -- browse
    //      foobars/add (GET) -- add_form
    //      foobars/add (POST) -- add
    //      foobars/123 -- view
    //      foobars/123/edit (GET) -- edit_form
    //      foobars/123/edit (POST) -- edit
    $vars = get_class_vars($site_class);
    $resources = either($vars['resources'], array());
    $res_len = 0;
    foreach ($resources as $resource) {
        if (preg_match("/^" . preg_quote($resource, '/') . "(\/|$)/", $in_path)) {
            $res_len = count(explode("/", $resource));
            $method = str_replace("/", "_", $resource);
            $params = array_slice($path, $res_len);
        }
    }
    if ($res_len) {
        if (!$params)
            $method .= "_browse";
        elseif ($params[0] == "add")
            if (count($_POST)) {
                $method .= "_add";
                $params = array($_POST);
            } else
                $method .= "_add_form";
        elseif ($params[1] == "edit")
            if (count($_POST)) {
                $method .= "_edit";
                $params = array($params[0], $_POST);
            } else
                $method .= "_edit_form";
        else
            $method .= "_view";
    }

    // Password protection
    if (!$_SERVER['PHP_AUTH_USER']) {
        $auth_data = either($_SERVER['REDIRECT_REMOTE_USER'], $_SERVER['REMOTE_USER']);
        $auth_data = base64_decode(substr($auth_data, 6));
        list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $auth_data);
    }
    $protected = either($vars['protected'], array());
    foreach ($protected as $protected_path => $auth) {
        $is_protected = strpos($in_path, $protected_path) === 0;
        $is_authed = $auth['username'] == $_SERVER['PHP_AUTH_USER'] &&
            $auth['password'] == $_SERVER['PHP_AUTH_PW'];
        if ($is_protected && !$is_authed) {
            header('WWW-Authenticate: Basic realm="' . SITE_NAME . ' Admin"');
            header('HTTP/1.0 401 Unauthorized');
            echo "<html><head><meta HTTP-EQUIV=\"REFRESH\" content=\"0; url=http://www.usgo.org/tournaments/crosstab\"></head></html>";
            exit;
        }
    }

    // Hand off to class method
    $method = str_replace("-", "_", $method);
    if (!in_array($method, get_class_methods($site_class))) {
        header("HTTP/1.0 404 Not Found");
        die("<h1>Not Found</h1><p>The method \"" . htmlentities($method) . "\" cannot be found.</p>");
    } else {
        call_user_func_array(array($site_class, $method), (array)$params);
    }
}

function either($a, $b) {
    return $a ? $a : $b;
}

function href($path) {
    return URL_ROOT . $path;
}

// Allow only alphanumeric characters and dashes in URL params
function pacify_path($path) {
    return strtolower(preg_replace("/[^a-zA-Z0-9-]/", "", $path));
}

function redir($path, $show_feedback=false, $extra="") {
    $url = URL_ROOT . $path;
    $url .=  ($show_feedback ? "?success=1" : "");
    $url .= ($extra ? ($show_feedback ? "&" : "?") . "extra=" . urlencode($extra) : "");
    header("location: " . $url);
    exit;
}

function head($title=null) {
    include(HEADER_PATH);
    if ($_REQUEST['path']) {
        $breadcrumbs = array_slice(explode("/", $_REQUEST['path']), 0, -1);
        echo "<div id='breadcrumbs'>";
        echo "<a href='" . URL_ROOT . "'>" . SITE_NAME . "</a>";
        $trail = array(preg_replace("/\/$/", "", URL_ROOT));
        foreach ($breadcrumbs as $crumb) {
            $trail[] = $crumb;
            echo " &gt; <a href='". implode("/", $trail) . "'>$crumb</a>";
        }
        echo "</div>";
    }
    if ($title) echo "<h2>$title</h2>";
    if ($_REQUEST['success']) {
        // Directly echoing "extra" isn't strictly secure, but oh well
        // TODO: improve this
        echo "<p id='feedback'>Success! " . (either($_REQUEST['extra'], "")) . "</p>";
    }
}

function foot() {
    include(FOOTER_PATH);
}

function content($title, $content) {
    head($title);
    echo $content;
    foot();
}

/**
 * Sorts an array of assoc arrays (e.g., database rows) by any number of
 * columns and directions (SORT_ASC, SORT_DESC, SORT_REGULAR, SORT_NUMERIC,
 * SORT_STRING). Examples:
 *
 *     $rows = array(
 *         array("foo" => 1, "bar" => "Widget waxy woddle"),
 *         array("foo" => 11, "bar" => "Borp dorp da doo"),
 *         ...);
 *     sort_rows($rows, "foo");
 *     sort_rows($rows, "foo", SORT_STRING, SORT_DESC, "bar");
 *
 * Any string values will be lower-cased to force case-insensitive sorts.
 *
 * Will sort by the first column if no columns are specified, and use SORT_ASC
 * for direction if none is specified.
 *
 * Returns false when there's a problem, otherwise true.
 *
 * This isn't perfectly efficient (traverses the array at least twice, creates
 * a temporary array for each sort column) but since it uses the built-in
 * array_multisort(), it's still much faster than a usort()-based approach.
**/
function sort_get_safe_valuesrows(&$rows) {
    if (!count($rows) || !is_array($rows[0]))
        return false;

    $first_col = key($rows[0]);
    if ($first_col === 0)
        return false; // Assoc arrays only

    $args = array_slice(func_get_args(), 1);
    $cols = array();
    $cols_to_dirs = array();
    $curcol = null;
    for ($i = 0; $i < count($args); $i++) {
        if (is_string($args[$i]))
            $cols[] = $curcol = $args[$i];
        else {
            if (is_null($curcol))
                $curcol = $first_col;
            $cols_to_dirs[$curcol][] = $args[$i];
        }
    }
    if (!count($cols)) {
        $cols[] = $curcol = $first_col;
        if (!isset($cols_to_dirs[$curcol]))
            $cols_to_dirs[$curcol][] = SORT_ASC;
    }

    $col_arrs = array();
    foreach ($rows as $idx => $row)
        foreach ($cols as $col)
            $col_arrs[$col][$idx] = (is_string($row[$col]) ?
                strtolower($row[$col]) : $row[$col]);

    $multisort_args = array();
    $sort_asc = SORT_ASC; // hack needed for PHP 5.3
    foreach ($cols as $idx => $col) {
        $multisort_args[] = &$col_arrs[$col];
        if (isset($cols_to_dirs[$col]))
            foreach ($cols_to_dirs[$col] as $dir)
                $multisort_args[] = &$dir;
        else
            $multisort_args[] = &$sort_asc;
    }
    $multisort_args[] = &$rows;
    call_user_func_array("array_multisort", $multisort_args);
    return true;
}

// Return a table for all rows from a query, hyperlinking to the first col
// with the name of the second col
function browse_table($select, $base_href="") {
    $rows = fetch_rows($select);
    if (!count($rows))
        return "<p><i>None</i></p>\n";
    $retval = "<table class='browse-table'>\n<tr>";
    foreach (array_slice(array_keys($rows[0]), 1) as $key)
        $retval .= "<th>" . htmlentities($key) . "</th>";
    $retval .= "</tr>\n";
    $href = "";
    foreach ($rows as $row) {
        $row_num++;
        $retval .= "<tr>";
        $col_num = 0;
        $col1 = "";
        foreach ($row as $key => $value) {
            $col_num++;
            $out_value = htmlentities($value);
            $data = "";
            if ($col_num == 1) {
                $href = ($out_value && $base_href ? href($base_href . $out_value) : "");
                $col1 = $out_value;
                continue;
            } elseif ($col_num == 2 && $href) {
                $out_value = "<a href='$href'>$out_value</a>";
            } elseif ($col_num == 2) {
                $data = " data-col1='$col1'";
            }
            $retval .= "<td$data>$out_value</td>";
        }
        $retval .= "</tr>\n";
    }
    $retval .= "</table>\n";
    return $retval;
}

function fetch_row($select) {
    $res = mysqli_query($GLOBALS['mysqli_link'], $select);
    if (!$res) return null;
    return mysqli_fetch_assoc($res);
}

function fetch_rows($select) {
    $res = mysqli_query($GLOBALS['mysqli_link'], $select);
    if (!$res) return array();
    $rows = array();
    while ($row = mysqli_fetch_array($res)) {
        $rows[] = $row;
    }
    return $rows;
}

function fetch_result($select, $row=0, $field=0) {
    // Store the query result for mysqli_link.
    $res = mysqli_query($GLOBALS['mysqli_link'], $select);

    // If the result has nothing return null.
    if (!$res) return null;

    // Emulate the function of mysql_result($res, $row, $field)
    // Convert mysql_result function.
    mysqli_data_seek($res, $row);
    $row_result = mysqli_fetch_row($res);

    var_dump("row_result");

    return $row_result[$field];
}

function get_safe_values($values) {
    $safe_values = array();
    $safe_keys = array();
    foreach ($values as $key => $value) {
        $safe_values[] = ($value == "now()" ? "now()" : "'" . mysqli_real_escape_string($GLOBALS['mysqli_link'], $value) . "'");
        $safe_keys[] = mysqli_real_escape_string($GLOBALS['mysqli_link'], $key);
    }
    return array($safe_keys, $safe_values);
}

function insert_row($table, $values) {
    list($safe_keys, $safe_values) = get_safe_values($values);
    $table = mysqli_real_escape_string($GLOBALS['mysqli_link'], $table);
    $safe_keys = implode(",", $safe_keys);
    $safe_values = implode(",", $safe_values);
    $query = "insert into `" . $table . "`" . " (" . $safe_keys . ")" . " values (" . $safe_values. ") ";

    @mysqli_query($GLOBALS['mysqli_link'], $query);

    return mysqli_insert_id($GLOBALS['mysqli_link']);
}

function update_rows($table, $values, $where) {
    list($safe_keys, $safe_values) = get_safe_values($values);
    $query = "update `" . mysqli_real_escape_string($GLOBALS['mysqli_link'], $table) . "` set ";
    for ($i = 0; $i < count($safe_keys); $i++)
        $query .= $safe_keys[$i] . "=" . $safe_values[$i] . ", ";
    $query = preg_replace("/, $/", "", $query);
    $query .= " where $where ";
    mysqli_query($GLOBALS['mysqli_link'], $query);
}

function delete_rows($table, $where="") {
    $query = "delete from `" . mysqli_real_escape_string($GLOBALS['mysqli_link'], $table) . "`" .
        ($where ? " where $where" : "");
    print_r($query);
    mysqli_query($GLOBALS['mysqli_link'], $query);
}

function get_checkboxes($rows, $name, $value, $text, $checked_field="") {
    if (!$checked_field) $checked_field = $value;
    $retval = "";
    foreach ($rows as $row) {
        $retval .= "<div>" .
            "<input name='${name}[]' type='checkbox' id='cb-" . $row[$value] . "'" .
                " value='" . $row[$value] . "'" .
                ($row[$checked_field] ? " checked" : "") . "> " .
            "<label for='cb-" . $row[$value] . "'>" . $row[$text] . "</label>" .
            "</div>";
    }
    return $retval;
}

function get_select($rows, $name, $value, $text, $default="", $selected_field="") {
    $retval = "<select id='$name' name='$name'>";
    if ($default)
        $retval .= "<option value=''>$default</option>";
    foreach ($rows as $row)
        $retval .= "<option value='" . $row[$value] . "'" .
            ($row[$selected_field] ? " selected" : "") . ">" .
            $row[$text] . "</option>";
    $retval .= "</select>";
    return $retval;
}

function strip_recursive(&$var) {
	if (is_array($var))
		foreach ($var as $key => $value)
			if (is_array($value)) strip_recursive($var[$key]);
			else $var[$key] = stripslashes($value);
}

function damn_magic_quotes_to_hell() {
    if (get_magic_quotes_gpc()) {
    	strip_recursive($_GET);
    	strip_recursive($_POST);
    	strip_recursive($_COOKIE);
    	strip_recursive($_REQUEST);
    }
}

damn_magic_quotes_to_hell();

?>
