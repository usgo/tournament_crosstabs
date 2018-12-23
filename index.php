<?php
include("util.php");
include("conf.php");

connect(DB_HOST, DB_NAME, DB_USERNAME, DB_PASSWORD);
dispatch("Site");

//////////////////////////////////////////////////////////////////////////////
// Site pages/actions (see below for helper functions)
//////////////////////////////////////////////////////////////////////////////

class Site {
    
    var $resources = array(
        "results",
        "players",
        "rounds",
        "admin/results",
        "admin/rounds",
        "admin/bands",
        "admin/import");
        
    var $protected = array(
        "admin" => array("username" => ADMIN_USERNAME, "password" => ADMIN_PASSWORD));
    
    function home() {
        head();
        echo "<h2>AGA Tournament Crosstabs</h2>
            <p>Current standings:</p>
            <ul>";
        $bands = fetch_rows("select bid, name from bands");
        foreach ($bands as $band) {
            echo "<li><a href='" . href("band-matrix/" . $band['bid']) . "'>" .
                $band['name'] . "</a></li>";
        }
        echo "</ul>";
        foot();
    }
    
    // Show results for all rounds in a band
    function band_matrix($bid=null) {
        if (!$bid)
            $bid = fetch_result("select max(bid) from bands");
        $band = fetch_row("select * from bands where bid='$bid'");
        head($band['name']);
        result_matrix_band($bid);
        foot();
    }
    
    // Show all rounds
    function rounds_browse() {
        content(
            "Archived Rounds",
            browse_table("select r.rid, concat_ws('', b.name, ', ',
                if(r.begins, concat_ws('', date_format(r.begins, '%c/%e'), ' - ', date_format(r.ends, '%c/%e')), r.name)) as round
                from rounds r join bands b on r.bid=b.bid
                order by b.name, r.begins desc",
                "rounds/"));
    }
    
    // Show result matrix for the most current rounds (one per band) or for a
    // specific round
    function rounds_view($rid) {
        head("Round Results");
        if ($rid == "current") {
            $latest_rounds = get_latest_rounds();
            foreach ($latest_rounds as $round) {
                echo "<h3>" . $round['round'] . "</h3>";
                echo result_matrix_round($round['rid']);
            }
        } else {
            $round = fetch_row("select concat_ws('', b.name, ', ',
                if(r.begins, concat_ws('', date_format(r.begins, '%c/%e'), ' - ', date_format(r.ends, '%c/%e')), r.name)) as round
                from rounds r join bands b on r.bid=b.bid
                where r.rid='$rid'");
            echo "<h3>" . $round['round'] . "</h3>";
            echo result_matrix_round($rid);
        }
        foot();
    }

    // Show full list of players
    function players_browse() {
        content(
            "Players",
            browse_table("select pid, name as player from players order by name", "players/"));
    }
    
    function players_view() {
        // TODO: implement and link to this
    }
    
    function results_browse() {
        redir("band-matrix");
    }
    
    // Display a game's SGF using EidoGo
    function results_view($ids) {
        list($rid, $pw, $pb) = explode("-", $ids);
        $result = fetch_row("select r.result, pw.name as white, pb.name as black,
            r.sgf, r.report_date as date
            from results r join players pw on r.pw=pw.pid
            join players pb on r.pb=pb.pid 
            where r.pw='$pw' and r.pb='$pb' and r.rid='$rid'");
        $sgf = href("sgf/" . htmlentities($result['sgf']));
        head($result['white'] . " (W) vs. " . $result['black'] . " (B)");
        echo "<p><a href='$sgf'>Download .SGF</a></p>";
        echo "<div class='eidogo-player-auto' sgf='$sgf'></div>";
        foot();
    }
    
    // Add a new game result
    function results_add_form() {
        head("Report Result");
        result_form("results/add");
        foot();
    }
    
    // Save a game result's SGF and insert details into the DB
    function results_add($values) {
        save_result($values, true);
        redir("rounds/" . $values['rid'], true,
            "<a href='" . href("results/add") . "'>Add another result?</a>");
    }
    
    // Spit out a <select> element of players for a given round
    function rounds_players_select($rid) {
        $players = fetch_rows("select p.pid, p.name
            from players p join players_to_rounds pr on p.pid=pr.pid and pr.rid='$rid'
            order by name");
        echo get_select($players, "{pids}", "pid", "name", "[Select a player...]");
    }
    
    // Admin front page
    function admin() {
        content(
            "Admin",
            "<ul>
                <li><a href='" . href("admin/results/add") . "'>Report Game Result</a></li>
                <li><a href='" . href("admin/import/add") . "'>Import Game Results</li>
                <li><a href='" . href("admin/results") . "'>Manage Game Results</a></li>
                <li><a href='" . href("admin/rounds") . "'>Manage Rounds</a></li>
                <li><a href='" . href("admin/bands") . "'>Manage Bands</a></li>
            </ul>");
    }
    
    function admin_results_add_form() {
        head("Report Result");
        result_form("admin/results/add");
        foot();
    }
    
    function admin_results_add($values) {
        save_result($values, true);
        redir("admin/results", true,
            "<a href='" . href("admin/results/add") . "'>Add another result?</a>");
    }
    
    function admin_import_add_form($values) {
        head("Import Results");
        if ($GLOBALS['feedback'])
            echo "<div id='error'>" . htmlentities($GLOBALS['feedback']) . "</div>";
        ?>
        <form action="<?= href("admin/import/add"); ?>" method="post" enctype="multipart/form-data">
            <div>Band:</div>
            <?php
            $bands = fetch_rows("select bid, name from bands order by name");
            echo get_select($bands, "bid", "bid", "name", "[Select a band...]");
            ?>
            <div>File type:</div>
            <select name="type">
                <!-- <option value="pytd_html">PyTD HTML report</option> -->
                <option value="pytd_json">PyTD JSON</option>
                <option value="pytd_xml">PyTD XML</option>
                <!-- <option value="opengotha_xml">OpenGotha XML</option> -->
                <option value="aga_txt">AGA Results TXT</option>
            </select>
            <div>File:</div>
            <input type="file" name="file">
            <input type="submit" value="Import">
        </form>
        <?php
        foot();
    }

    function _import_pytd_xml($bid, $data) {
        $xml = new SimpleXMLElement($data);
        
        $new_players = array(); // Map of player ID -> player record
        foreach ($xml->PLAYERS->PLAYER as $player) {
            $name_parts = explode(", ", (string)$player['name']);
            $name = implode(" ", array_slice($name_parts, 1)) . " " . $name_parts[0];
            $new_players[(string)$player['id']] = array("name" => $name);
        }

        // Map of existing player names to records
        $old_player_rows = fetch_rows("select * from players p, players_to_bands pb
            where p.pid=pb.pid and pb.bid='$bid'");
        $old_players = array();
        foreach ($old_player_rows as $old_player_row)
            $old_players[$old_player_row['name']] = $old_player_row;
        
        // Insert any new players that haven't been inserted yet
        $player_count = 0;
        $num = fetch_result("select max(num) from players p, players_to_bands pb
            where p.pid=pb.pid and pb.bid='$bid'");
        if (!$num)
            $num = 0;
        foreach ($new_players as $player_id => $new_player) {
            if ($old_players[$new_player['name']]) {
                $all_players[$player_id] = array(
                    "pid" => $old_players[$new_player['name']]['pid'],
                    "name" => $new_player['name']);
                continue;
            }
            $num++;
            $pid = insert_row("players", array("name" => $new_player['name'], "num" => $num));
            insert_row("players_to_bands", array("pid" => $pid, "bid" => $bid));
            $player_count++;
            $all_players[$player_id] = array(
                "pid" => $pid,
                "name" => $new_player['name']);
        }
        
        $rounds = array();
        $results = array();
        $round_count = 0;
        $result_count = 0;
        foreach ($xml->ROUND as $round) {
            $round_name = "Round " . intval(strval($round['number']));
            $round_row = fetch_row("select * from rounds where bid='$bid'
                and name='$round_name'");
            if ($round_row) {
                $rid = $round_row['rid'];
                // Existing results
                $result_rows = fetch_rows("select * from results where rid='$rid'");
                $results = array();
                foreach ($result_rows as $result_row)
                    $results[implode("-", array($result_row['rid'], $result_row['pw'], $result_row['pb']))] = 1;
            } else {
                $rid = insert_row("rounds", array("bid" => $bid, "name" => $round_name));
                $round_count++;
                $results = array();
            }
            foreach ($round->PAIR as $pairing) {
                if ((string)$pairing['result'] == "1")
                    $result = "W+";
                elseif ((string)$pairing['result'] == "2")
                    $result = "B+";
                elseif ((string)$pairing['result'] == "0")
                    $result = "NR";
                else
                    continue;
                $w_id = (string)$pairing['wid'];
                $b_id = (string)$pairing['bid'];
                $result = array(
                    "rid" => $rid,
                    "result" => $result,
                    "report_date" => "now()",
                    "pw" => $all_players[$w_id]['pid'],
                    "pb" => $all_players[$b_id]['pid']);
                $result_key = implode("-", array($result['rid'], $result['pw'], $result['pb']));
                if ($results[$result_key]) {
                    update_rows("results", $result, "rid='$rid' and pw='" . $result['pw'] .
                        "' and pb='" . $result['pb'] . "'");
                    $result_count++;
                } else {
                    insert_row("results", $result);
                    $result_count++;
                }
            }
        }
        return array($player_count, $round_count, $result_count);
    }

    function _import_pytd_json($bid, $data) {
        $player_count = 0;
        $round_count = 0;
        $result_count = 0;

        $json = json_decode($data, true);
        
        // Import players
        $new_players = array(); // Map of player ID -> player record
        $json_players = $json['players'];
        sort_rows($json_players, "rating", SORT_DESC);
        foreach ($json_players as $player) {
            $name = $player['first_name'] . " " . $player['last_name'];
            $new_players[$player['id']] = array("name" => $name);
        }
        
        // Map of existing player names to records
        $old_player_rows = fetch_rows("select * from players p, players_to_bands pb
            where p.pid=pb.pid and pb.bid='$bid'");
        $old_players = array();
        foreach ($old_player_rows as $old_player_row)
            $old_players[$old_player_row['name']] = $old_player_row;
        
        // Insert any new players that haven't been inserted yet
        $player_count = 0;
        $num = fetch_result("select max(num) from players p, players_to_bands pb
            where p.pid=pb.pid and pb.bid='$bid'");
        if (!$num)
            $num = 0;
        foreach ($new_players as $player_id => $new_player) {
            if ($old_players[$new_player['name']]) {
                $all_players[$player_id] = array(
                    "pid" => $old_players[$new_player['name']]['pid'],
                    "name" => $new_player['name']);
                continue;
            }
            $num++;
            $pid = insert_row("players", array("name" => $new_player['name'], "num" => $num));
            insert_row("players_to_bands", array("pid" => $pid, "bid" => $bid));
            $player_count++;
            $all_players[$player_id] = array(
                "pid" => $pid,
                "name" => $new_player['name']);
        }

        // Import rounds and game results
        $rounds = array();
        $results = array();
        foreach ($json['circuits'] as $round) {
            $round_name = "Round " . intval(strval($round['number']));
            $round_row = fetch_row("select * from rounds where bid='$bid'
                and name='$round_name'");
            if ($round_row) {
                $rid = $round_row['rid'];
                // Existing results
                $result_rows = fetch_rows("select * from results where rid='$rid'");
                $results = array();
                foreach ($result_rows as $result_row)
                    $results[implode("-", array($result_row['rid'], $result_row['pw'], $result_row['pb']))] = 1;
            } else {
                $rid = insert_row("rounds", array("bid" => $bid, "name" => $round_name));
                $round_count++;
                $results = array();
            }
            foreach ($round['games'] as $pairing) {
                if ($pairing['winner_id']) {
                    $result = $pairing['winner_id'] == $pairing['black_id'] ? "B+" : "W+";
                } else {
                    $result = "NR";
                }
                $w_id = $pairing['white_id'];
                $b_id = $pairing['black_id'];
                $result = array(
                    "rid" => $rid,
                    "result" => $result,
                    "report_date" => "now()",
                    "pw" => $all_players[$w_id]['pid'],
                    "pb" => $all_players[$b_id]['pid']);
                $result_key = implode("-", array($result['rid'], $result['pw'], $result['pb']));
                if ($results[$result_key]) {
                    update_rows("results", $result, "rid='$rid' and pw='" . $result['pw'] .
                        "' and pb='" . $result['pb'] . "'");
                    $result_count++;
                } else {
                    insert_row("results", $result);
                    $result_count++;
                }
            }
        }

        return array($player_count, $round_count, $result_count);
    }

    function _import_opengotha_xml($bid, $data) {
        $xml = new SimpleXMLElement($data);
        var_dump($xml);
        
        $new_players = array(); // Map of player ID -> player record
        foreach ($xml->Players->Player as $player) {
            $id = strtoupper((string)$player['name']) . strtoupper((string)$player['firstName']);
            $new_players[$id] = array("name" => (string)$player['firstName'] . ' ' . (string)$player['name']);
        }
        var_dump($new_players);

        // Map of existing player names to records
        // $old_player_rows = fetch_rows("select * from players p, players_to_bands pb
        //     where p.pid=pb.pid and pb.bid='$bid'");
        // $old_players = array();
        // foreach ($old_player_rows as $old_player_row)
        //     $old_players[$old_player_row['name']] = $old_player_row;
        
        // Insert any new players that haven't been inserted yet
        $player_count = 0;
        // $num = fetch_result("select max(num) from players p, players_to_bands pb
        //     where p.pid=pb.pid and pb.bid='$bid'");
        // if (!$num)
        //     $num = 0;
        // foreach ($new_players as $player_id => $new_player) {
        //     if ($old_players[$new_player['name']]) {
        //         $all_players[$player_id] = array(
        //             "pid" => $old_players[$new_player['name']]['pid'],
        //             "name" => $new_player['name']);
        //         continue;
        //     }
        //     $num++;
        //     $pid = insert_row("players", array("name" => $new_player['name'], "num" => $num));
        //     insert_row("players_to_bands", array("pid" => $pid, "bid" => $bid));
        //     $player_count++;
        //     $all_players[$player_id] = array(
        //         "pid" => $pid,
        //         "name" => $new_player['name']);
        // }
        
        // $rounds = array();
        // $results = array();
        $round_count = 0;
        $result_count = 0;
        // foreach ($xml->ROUND as $round) {
        //     $round_name = "Round " . intval(strval($round['number']));
        //     $round_row = fetch_row("select * from rounds where bid='$bid'
        //         and name='$round_name'");
        //     if ($round_row) {
        //         $rid = $round_row['rid'];
        //         // Existing results
        //         $result_rows = fetch_rows("select * from results where rid='$rid'");
        //         $results = array();
        //         foreach ($result_rows as $result_row)
        //             $results[implode("-", array($result_row['rid'], $result_row['pw'], $result_row['pb']))] = 1;
        //     } else {
        //         $rid = insert_row("rounds", array("bid" => $bid, "name" => $round_name));
        //         $round_count++;
        //         $results = array();
        //     }
        //     foreach ($round->PAIR as $pairing) {
        //         if ((string)$pairing['result'] == "1")
        //             $result = "W+";
        //         elseif ((string)$pairing['result'] == "2")
        //             $result = "B+";
        //         elseif ((string)$pairing['result'] == "0")
        //             $result = "NR";
        //         else
        //             continue;
        //         $w_id = (string)$pairing['wid'];
        //         $b_id = (string)$pairing['bid'];
        //         $result = array(
        //             "rid" => $rid,
        //             "result" => $result,
        //             "report_date" => "now()",
        //             "pw" => $all_players[$w_id]['pid'],
        //             "pb" => $all_players[$b_id]['pid']);
        //         $result_key = implode("-", array($result['rid'], $result['pw'], $result['pb']));
        //         if ($results[$result_key]) {
        //             update_rows("results", $result, "rid='$rid' and pw='" . $result['pw'] .
        //                 "' and pb='" . $result['pb'] . "'");
        //             $result_count++;
        //         } else {
        //             insert_row("results", $result);
        //             $result_count++;
        //         }
        //     }
        // }
        return array($player_count, $round_count, $result_count);
    }

    function _import_aga_txt($bid, $data) {        
        $player_count = 0;
        $round_count = 0;
        $result_count = 0;

        $lines = explode("\n", $data);
        
        // Import players
        $new_players = array(); // Map of player ID -> player record
        while ($line = next($lines)) {
            if ($line === "PLAYERS") {
                while ($line = next($lines)) {
                    if ($line === "") {
                        break;
                    }
                    list($id, $name, $rank) = explode("\t", $line);
                    $name_parts = explode(", ", $name);
                    $name = implode(" ", array_slice($name_parts, 1)) . " " . $name_parts[0];
                    $new_players[$id] = array("name" => $name);
                }
                break;
            }
        }

        // Map of existing player names to records
        $old_player_rows = fetch_rows("select * from players p, players_to_bands pb
            where p.pid=pb.pid and pb.bid='$bid'");
        $old_players = array();
        foreach ($old_player_rows as $old_player_row)
            $old_players[$old_player_row['name']] = $old_player_row;
        
        // Insert any new players that haven't been inserted yet
        $num = fetch_result("select max(num) from players p, players_to_bands pb
            where p.pid=pb.pid and pb.bid='$bid'");
        if (!$num)
            $num = 0;
        foreach ($new_players as $player_id => $new_player) {
            if ($old_players[$new_player['name']]) {
                $all_players[$player_id] = array(
                    "pid" => $old_players[$new_player['name']]['pid'],
                    "name" => $new_player['name']);
                continue;
            }
            $num++;
            $pid = insert_row("players", array("name" => $new_player['name'], "num" => $num));
            insert_row("players_to_bands", array("pid" => $pid, "bid" => $bid));
            $player_count++;
            $all_players[$player_id] = array(
                "pid" => $pid,
                "name" => $new_player['name']);
        }

        // Import rounds and game results
        $rounds = array();
        $results = array();
        $new_players = array(); // Map of player ID -> player record
        $round_num = 1;
        while ($line = next($lines)) {
            if (strpos($line, "GAMES ") === 0) {
                $round_name = "Round " . $round_num;
                $round_row = fetch_row("select * from rounds where bid='$bid'
                    and name='$round_name'");
                if ($round_row) {
                    $rid = $round_row['rid'];
                    // Existing results
                    $result_rows = fetch_rows("select * from results where rid='$rid'");
                    $results = array();
                    foreach ($result_rows as $result_row)
                        $results[implode("-", array($result_row['rid'], $result_row['pw'], $result_row['pb']))] = 1;
                } else {
                    $rid = insert_row("rounds", array("bid" => $bid, "name" => $round_name));
                    $round_count++;
                    $results = array();
                }
                while ($line = next($lines)) {
                    if ($line === "") {
                        break;
                    }
                    list($w_id, $b_id, $winner_color) = explode("\t", $line);
                    if ($winner_color === 'W') {
                        $result = "W+";
                    } else if ($winner_color === 'B') {
                        $result = "B+";
                    } else {
                        $result = "NR";
                    }
                    $result = array(
                        "rid" => $rid,
                        "result" => $result,
                        "report_date" => "now()",
                        "pw" => $all_players[$w_id]['pid'],
                        "pb" => $all_players[$b_id]['pid']);
                    $result_key = implode("-", array($result['rid'], $result['pw'], $result['pb']));
                    if ($results[$result_key]) {
                        update_rows("results", $result, "rid='$rid' and pw='" . $result['pw'] .
                            "' and pb='" . $result['pb'] . "'");
                        $result_count++;
                    } else {
                        insert_row("results", $result);
                        $result_count++;
                    }
                }
                $round_num++;
            }
        }

        return array($player_count, $round_count, $result_count);
    }
    
    function admin_import_add($values) {
        $bid = intval($values['bid']);
        
        if ($_FILES['file'] && $_FILES['file']['error'] == 0) {
            $data = file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $GLOBALS['feedback'] = "Error uploading file";
            self::admin_import_add_form($values);
            exit;
        }

        if ($values['type'] == "pytd_xml") {
            list($player_count, $round_count, $result_count) = self::_import_pytd_xml($bid, $data);
        } else if ($values['type'] == "pytd_json") {
            list($player_count, $round_count, $result_count) = self::_import_pytd_json($bid, $data);
        } else if ($values['type'] == "opengotha_xml") {
            list($player_count, $round_count, $result_count) = self::_import_opengotha_xml($bid, $data);
        } else if ($values['type'] === "aga_txt") {
            list($player_count, $round_count, $result_count) = self::_import_aga_txt($bid, $data);
        }
        
        head("Import Complete");
        echo "<p><ul>
            <li>$player_count players imported</li>
            <li>$round_count rounds imported</li>
            <li>$result_count game results imported</li>
            </ul></p>";
        foot();
    }
    
    // Show all bands for admin editing
    function admin_bands_browse() {
        content(
            "Bands",
            "<p><a href='" . href("admin/bands/add") . "'>Add Band</a></p>" .
            browse_table("select bid, name as band from bands order by name", "admin/bands/"));
    }
    
    // View a band's players, with option to add new players
    // TODO: ability to remove players from band
    function admin_bands_view($bid, $checkboxes=false) {
        $band = fetch_row("select * from bands where bid='$bid'");
        head("Band: " . htmlentities($band['name']));
        echo browse_table(
            "select p.pid, p.name as player, p.num
                from players p join players_to_bands pb on p.pid=pb.pid and pb.bid='$bid'
                order by name");
        ?>
        <form action='<?=href("admin/bands/$bid/edit")?>' method='post'>
            Add players to this band (one name per line):<br>
            <textarea name="new_players"></textarea><br>
            <input type="submit" value="Add Players">
        </form>
        <script>
        (function() {
            $(".browse-table td:first-child").each(function(i, td) {
                var delbtn = document.createElement("a");
                delbtn.className = "del-player";
                delbtn.innerHTML = "&times;";
                delbtn.href = "#";
                td.appendChild(delbtn);
                $(delbtn).click(function() {
                    var sure = confirm("Are you sure you want to delete that player? All of the player's" +
                        " games will also be deleted.");
                    if (!sure)
                        return;
                    var pid = td.getAttribute("data-col1");
                    $.post("<?=href("admin-player-delete")?>", {pid: pid}, function() {
                        var tr = td.parentNode;
                        tr.parentNode.removeChild(tr);
                    });
                });
            });
        })();
        </script>
        <?php
        foot();
    }
    
    // Delete a player
    function admin_player_delete() {
        $pid = (int)$_POST['pid'];
        delete_rows("players", "pid='$pid'");
        delete_rows("players_to_bands", "pid='$pid'");
        delete_rows("players_to_rounds", "pid='$pid'");
        delete_rows("results", "pw='$pid' or pb='$pid'");
    }
    
    // Add new players to a band
    function admin_bands_edit($bid, $values) {
        insert_new_players($bid, $values['new_players']);
        redir("admin/bands/$bid", true);
    }
    
    // Show form to add a new band
    function admin_bands_add_form() {
        head("Add Band");
        ?>
        <form action="<?=href("admin/bands/add")?>" method="post">
        <div>Band name:</div>
        <input type="text" name="name">
        <div>Players, one name per line:</div>
        <textarea name="new_players"></textarea>
        <input type="submit" value="Add Band">
        </form>
        <?php
        foot();
    }
    
    // Insert a new band into the DB
    function admin_bands_add($values) {
        $bid = insert_row("bands", array("name" => $values['name']));
        insert_new_players($bid, $values['new_players']);
        redir("admin/bands", true);
    }
    
    // Show all rounds for admin editing
    function admin_rounds_browse() {
        content(
            "Rounds",
            "<p><a href='" . href("admin/rounds/add") . "'>Add Round</a></p>" .
            browse_table("select r.rid, concat_ws('', b.name, ', ',
                if(r.begins, concat_ws('', date_format(r.begins, '%c/%e'), ' - ', date_format(r.ends, '%c/%e')), r.name)) as round
                from rounds r join bands b on r.bid=b.bid
                order by r.begins desc",
                "admin/rounds/"));
    }
    
    // Show players for a band, with options to activate/deactivate them using
    // checkboxes
    function admin_rounds_view($rid) {
        $round = fetch_row("select r.name as name, concat(date_format(begins, '%c/%e'), ' - ',
            date_format(ends, '%c/%e')) as date_range, r.*, b.name as band
            from rounds r join bands b on r.bid=b.bid
            where rid='$rid'");
        $players = fetch_rows("select distinct p.pid, p.name, pr.pid as in_round
            from players p join players_to_bands pb
            on p.pid=pb.pid and pb.bid='" . $round['bid'] . "'
            left join players_to_rounds pr on p.pid=pr.pid and pr.rid='$rid'
            order by p.name");
        head("Round: " . $round['date_range'] . ", Band " . $round['band']);
        ?>
        <form action='<?=href("admin/rounds/$rid/edit")?>' method='post'>
        <div>Name:</div>
        <input type="text" name="name" size="20" value='<?=$round['name']?>'>
        <div>Begin date:</div>
        <input type="text" name="begins" size="10" value='<?=$round['begins']?>'> <span>YYYY-MM-DD</span>
        <div>End date:</div>
        <input type="text" name="ends" size="10" value='<?=$round['ends']?>'> <span>YYYY-MM-DD</span>
        <p>Players:</p>
        <div id='players'>
        <?= get_checkboxes($players, "pids", "pid", "name", "in_round") ?>
        </div>
        <input type='submit' value='Update Round'>
        </form>
        <?php
        foot();
    }
    
    // Update a band's player list
    function admin_rounds_edit($rid, $values) {
        update_rows("rounds", array(
            "name" => $values['name'],
            "begins" => $values['begins'],
            "ends" => $values['ends']),
            "rid='$rid'");
        delete_rows("players_to_rounds", "rid='$rid'");
        foreach ($values['pids'] as $pid) {
            insert_row("players_to_rounds", array("pid" => $pid, "rid" => $rid));
        }
        redir("admin/rounds/$rid", true);
    }
    
    // Show form to add a new round
    function admin_rounds_add_form() {
        head("Add Round");
        ?>
        <form action='<?=href("admin/rounds/add")?>' method='post'>
        <div>Band:</div>
        <?php
            $bands = fetch_rows("select bid, name from bands order by name");
            echo get_select($bands, "bid", "bid", "name", "[Select a band...]");
        ?>
        <div>Name:</div>
        <input type="text" name="name" size="20">
        <div>Begin date:</div>
        <input type="text" name="begins" size="10"> <span>YYYY-MM-DD</span>
        <div>End date:</div>
        <input type="text" name="ends" size="10"> <span>YYYY-MM-DD</span>
        <div>Players:</div>
        <div id='players'>[Select a band]</div>
        <input type="submit" value="Add Round">
        </form>
        
        <script>
        (function() {
            function updateCheckboxes(bid) {
                if (!bid) return;
                $("#players").load("../../bands-players-checkboxes/" + bid);
            }
            $("#bid").bind("change", function() { updateCheckboxes(this.value); });
            updateCheckboxes($("#bid")[0].value);
        })();
        </script>
        <?php
        foot();
    }
    
    // Spit out checkboxes for players within a given band
    function bands_players_checkboxes($bid) {
        $player_select = "select p.pid, p.name as player
            from players p join players_to_bands pb on p.pid=pb.pid and pb.bid='$bid'
            order by name";
        echo get_checkboxes(fetch_rows($player_select), "pids", "pid", "player");
    }
    
    // Insert band details and players into the DB
    function admin_rounds_add($values) {
        $rid = insert_row("rounds", array(
            "bid" => $values['bid'],
            "name" => $values['name'],
            "begins" => $values['begins'],
            "ends"  => $values['ends']));
        foreach ($values['pids'] as $pid)
            insert_row("players_to_rounds", array("pid" => $pid, "rid" => $rid));
        redir("admin/rounds", true);
    }
    
    function admin_results_browse() {
        head("Game Results");
        echo browse_table("select concat(r.rid, '-', pw, '-', pb), r.result,
                pw.name as white, pb.name as black, report_date as date
                from results r join players pw on r.pw=pw.pid
                join players pb on r.pb=pb.pid
                order by report_date desc",
            "admin/results/");
        foot();
    }
    
    function admin_results_view($ids) {
        list($rid, $pw, $pb) = explode("-", $ids);
        head("Edit Game Result");
        $result = fetch_row("select pw, pb, rid, result, points, sgf, report_date
            from results where pw='$pw' and pb='$pb' and rid='$rid'");
        result_form("admin/results/$ids/edit", $result);
        foot();
    }
    
    function admin_results_edit($ids, $values) {
        save_result($values);
        redir("admin/results", true);
    }
    
    function fiximport() {
        $json = json_decode(file_get_contents("usopen_2014_round1.json"), true);
        foreach ($json['players'] as $player) {
            $name = $player['first_name'] . " " . $player['last_name'];
            $new_players[] = array(
                "id" => $player['id'],
                "name" => $name,
                "rating" => floatval($player['rating']));
        }
        sort_rows($new_players, "rating", SORT_DESC);
        
        $old_player_rows = fetch_rows("select p.pid, p.name, p.num
            from players p join players_to_bands pb on p.pid=pb.pid and pb.bid='169'");
        foreach ($old_player_rows as $op)
            $old_players[$op['name']] = $op;
        $num = 1;
        foreach ($new_players as $np) {
            $op = $old_players[$np['name']];
            if ($op) {
                update_rows("players", array("num" => $num++), "pid='" . $op['pid'] . "'");
            } else {
                var_dump("WTF");
            }
        }
    }
}


//////////////////////////////////////////////////////////////////////////////
// Helper functions
//////////////////////////////////////////////////////////////////////////////

// Show a table of all game results for a given round
function result_matrix_round($rid) {
    $players_x = fetch_rows("select p.pid, p.name
        from players p join players_to_rounds pr on p.pid=pr.pid and pr.rid='$rid'");
    // Include players no longer assigned to the round but that have results
    $orphans = fetch_rows("select p.pid, p.name
        from players p join results r on (p.pid=r.pw or p.pid=r.pb)
        where r.rid='$rid'");
    $orphan_ids = array();
    foreach ($orphans as $orphan) {
        $found = false;
        foreach ($players_x as $px)
            if ($px['pid'] == $orphan['pid']) $found = true;
        if (!$found) {
            $players_x[] = $orphan;
            $orphan_ids[] = $orphan['pid'];
        }
    }
    usort($players_x, create_function('$a, $b', 'return strcmp($a["name"], $b["name"]);'));
    $players_y = $players_x;
    $results = fetch_rows("select * from results where rid='$rid'");
    echo "<table class='result-matrix'>";
    $first_y = true;
    $first_x = true;
    echo "<tr><th>&nbsp;</th>";
    foreach ($players_x as $px) {
        echo "<th class='top'>" . $px['name'] . "</th>";
    }
    echo "<th class='score'>Score</th></tr>";
    foreach ($players_y as $py) {
        $wins = 0;
        $losses = 0;
        echo "<tr>";
        $first_x = true;
        foreach ($players_x as $px) {
            if ($first_x)
                echo "<th>" . $py['name'] . "</th>";
            list($result, $presult) = get_result($rid, $results, $px['pid'], $py['pid']);
            if ($presult == 1)
                $losses++;
            elseif ($presult == 2)
                $wins++;
            $is_orphan = (in_array($px['pid'], $orphan_ids) || in_array($py['pid'], $orphan_ids));
            $is_self = $px['pid'] == $py['pid'];
            $class = ($is_self || $is_orphan ? "x " : "");
            $class .= ($presult == 1 ? "loss" : ($presult == 2 ? "win" : ""));
            echo "<td class='$class'>" . ($is_self ? "&nbsp;" : $result) . "</td>";
            $first_x = false;
        }
        echo "<td class='score'>$wins-$losses</td>";
        echo "</tr>";
        $first_y = false;
    }
    echo "</table>";
}

// Show matrix of results for all rounds in a band
function result_matrix_band($bid) {
    $where = ($bid ? "where b.bid='$bid'" : "");
    $rounds = fetch_rows("select r.rid, r.name, concat_ws('', b.name, ', ',
        if(r.begins, concat_ws('', date_format(r.begins, '%c/%e'), ' - ', date_format(r.ends, '%c/%e')), r.name)) as round
        from rounds r join bands b on r.bid=b.bid
        $where
        order by b.name, r.begins desc",
        "rounds/");
    
    $result_matrix = array();
    
    $player_rows = fetch_rows("select p.pid, p.name, p.num
        from players p join players_to_bands pb on p.pid=pb.pid and pb.bid='$bid'");
    $players = array();
    foreach ($player_rows as $player_row) {
        $players[$player_row['pid']] = $player_row;
    }
    $results = fetch_rows("select re.* from results re join rounds ro on re.rid=ro.rid
        where ro.bid='$bid'");
    
    foreach ($players as $player) {
        $row = array();
        $wins = 0;
        $losses = 0;
        foreach ($rounds as $round) {
            $col_result = null;
            $color = null;
            foreach ($results as $result) {
                if ($result['rid'] != $round['rid'])
                    continue;
                $py = $player['pid'];
                if ($result['pw'] == $py) {
                    $px = $result['pb'];
                    $color = "W";
                } else if ($result['pb'] == $py) {
                    $px = $result['pw'];
                    $color = "B";
                } else {
                    continue;
                }
                list($summary, $winloss) = get_result($result['rid'], $results, $px, $py);
                $opp_num = $players[$px]['num'];
                if ($winloss == 1) {
                    $col_result = "$color${opp_num}-";
                    $losses++;
                } elseif ($winloss == 2) {
                    $col_result = "$color${opp_num}+";
                    $wins++;
                } elseif ($winloss === 0) {
                    $col_result = "$color${opp_num}";
                }
                $opp_name = $players[$px]['name'];
                $href = ($result['sgf'] ? "href='" . href("results/" . $result['rid'] .
                    "-" . $result['pw'] . "-" . $result['pb']) . "'" : "");
                $col_result = "<a title=\"vs. $opp_name\" $href>$col_result</a>";
            }
            $row[] = either($col_result, "x");
        }
        $player['wins'] = $wins;
        $player['losses'] = $losses;
        array_unshift($row, $player);
        $result_matrix[] = $row;
    }
    
    if ($_GET['sort'] == "player") {
        usort($result_matrix, create_function('$a, $b',
            'return ($a[0]["num"] > $b[0]["num"]);'));
    } else {
        usort($result_matrix, create_function('$a, $b',
            'return ($a[0]["wins"] == $b[0]["wins"] ?
                $a[0]["num"] > $b[0]["num"] :
                $a[0]["wins"] < $b[0]["wins"]);'));
    }
    
    echo "<table class='result-matrix'>";
    echo "<tr><th><a href='?sort=player'>Player</a>" .
        ($_GET['sort'] == "player" ? " &#9650;" : "") . "</th>";
    foreach ($rounds as $round) {
        echo "<th>" . $round['name'] . "</th>";
    }
    echo "<th class='score'><a href='?sort=wins'>Wins/Losses</a>" .
        ($_GET['sort'] != "player" ? " &#9660;" : "") . "</th></tr>";
    
    $first_y = true;
    $first_x = true;

    foreach ($result_matrix as $row) {
        echo "<tr>";
        $first_x = true;
        foreach ($row as $col) {
            if ($first_x) {
                echo "<th class='top'>" . $col['name'] . " &ndash; ". $col['num'] . "</th>";
            } else {
                $class = (strpos($col, "+<") === false ?
                    (strpos($col, "-<") !== false ? "loss" : "nr") :
                    "win");
                if ($col == "x") {
                    $class = "x";
                    $col = "&nbsp;";
                }
                echo "<td class='$class'>" . $col . "</td>";
            }
            $first_x = false;
        }
        echo "<td class='score'>" . $row[0]['wins'] . "-" . $row[0]['losses'] . "</td>";
        echo "</tr>";
        $first_y = false;
    }
    echo "</table>";
}

// Determine the end result of a game for use in the result matrix
function get_result($rid, $results, $pid1, $pid2) {
    if ($pid1 == $pid2) return array("-", 0);
    foreach ($results as $result) {
        if ($result['rid'] != $rid)
            continue;
        if (($pid1 == $result['pw'] || $pid1 == $result['pb']) &&
            ($pid2 == $result['pw'] || $pid2 == $result['pb'])) {
            if ($result['result'] == "NR" || !$result['result']) {
                $presult = 0;
                $retresult = $result['result'];
            } elseif (($result['result'] == "W+" && $pid1 == $result['pw']) ||
                      ($result['result'] == "B+" && $pid1 == $result['pb'])) {
                $presult = 1;
                $retresult = $result['result'] == "W+" ? "B-" : "W-";
            } else {
                $presult = 2;
                $retresult = $result['result'];
            }
            if ($result['sgf'])
                $retresult = "<a href='" . href("results/$rid-" . $result['pw'] .
                    "-" . $result['pb']). "'>$retresult</a>";
            return array($retresult, $presult);
        }
    }
    return array("-", 0);
}

// Get the latest round for each band
function get_latest_rounds() {
    $rounds = fetch_rows("select r.rid, concat_ws('', b.name, ', ',
        if(r.begins, concat_ws('', date_format(r.begins, '%c/%e'), ' - ', date_format(r.ends, '%c/%e')), r.name)) as round, r.bid
        from rounds r join bands b on r.bid=b.bid
        order by b.name, r.begins desc, r.name desc");
    // Show one round per band
    $latest_rounds = array();
    $bids = array();
    foreach ($rounds as $round) {
        if (!in_array($round['bid'], $bids)) {
            $latest_rounds[] = $round;
            $bids[] = $round['bid'];
        }
    }
    return $latest_rounds;
}

// Insert new players into the DB using one-name-per-line input source
function insert_new_players($bid, $input) {
    $new_players = preg_split("/(\r\n|\r|\n)/", $input);
    $num = fetch_result("select max(num) from players p, players_to_bands pb
        where p.pid=pb.pid and pb.bid='$bid'");
    if (!$num)
        $num = 0;
    foreach ($new_players as $new_player) {
        if (!$new_player) continue;
        $num++;
        $pid = insert_row("players", array("name" => $new_player, "num" => $num));
        insert_row("players_to_bands", array("pid" => $pid, "bid" => $bid));
    }
}

// Spit out form to edit result
function result_form($action, $values=array()) {
    ?>
    <form action="<?=href($action)?>" method="post" enctype="multipart/form-data">
        <div>Round:</div>
    <?php
        // if ($values['rid'])
            $rounds = fetch_rows("select r.rid, concat_ws('', b.name, ', ',
                if(r.begins, concat_ws('', date_format(r.begins, '%c/%e'), ' - ', date_format(r.ends, '%c/%e')), r.name)) as round, r.bid,
                r.rid='" . $values['rid'] . "' as selected
                from rounds r join bands b on r.bid=b.bid
                order by b.name, r.begins desc, r.name desc");
        // else
            // $rounds = get_latest_rounds();
        echo get_select($rounds, "rid", "rid", "round", "[Select a round...]", "selected");
        if ($values['pw'] && $values['pb']) {
            $pw = get_select(fetch_rows("select pid, name, pid='" . $values['pw'] . "' as selected
                from players order by name"), "pw", "pid", "name",
                "[Select a player...]", "selected");
            $pb = get_select(fetch_rows("select pid, name, pid='" . $values['pb'] . "' as selected
                from players order by name"), "pb", "pid", "name",
                "[Select a player...]", "selected");
        } else {
            $pw = "<select id='pw' name='pw'><option value=''>[Select a round]</option></select>";
            $pb = "<select id='pb' name='pb'><option value=''>[Select a round]</option></select>";
        }
    ?>
        <div>White player:</div>
        <span id='pw-shell'>
        <?=$pw?>
        </span>
        
        <div>Black player:</div>
        <span id='pb-shell'>
        <?=$pb?>
        </span>
        
        <div>Result:</div>
        <select id='result' name='result'>
            <option value='W+'<?=($values['result'] == "W+" ? "selected" : "")?>>White won</option>
            <option value='B+'<?=($values['result'] == "B+" ? "selected" : "")?>>Black won</option>
            <option value='NR'<?=($values['result'] == "NR" ? "selected" : "")?>>No result</option>
        </select>
        
        <div>SGF:</div>
        <?php
            if ($values['sgf'])
                echo "<a href='" . href("sgf/" . htmlentities($values['sgf'])) . "'>".
                    htmlentities($values['sgf']) . "</a><br>";
        ?>
        <input type="file" name="sgf">
        
        <input type='submit' value='Submit'>
    </form>
    <?php if (!$values['pw'] && !$values['pb']) { ?>
        <script>
        $("#rid").bind("change", function() {
            $.get("../../rounds-players-select/" + this.value, null, function(html) {
                $("#pw-shell").html(html.replace(/\{pids\}/g, "pw"));
                $("#pb-shell").html(html.replace(/\{pids\}/g, "pb"));
            });
        });
        </script>
    <?php
    }
}

// Insert or update result info as appropriate
function save_result($values, $insert=false) {
    list($pw, $pb, $rid) = array($values['pw'], $values['pb'], $values['rid']);
    $db_values = array(
        "rid" => $rid,
        "pw" => $pw,
        "pb" => $pb,
        "result" => $values['result'],
        "points" => $values['points'],
        "report_date" => "now()");
    if ($_FILES['sgf'] && $_FILES['sgf']['error'] == 0) {
        $sgf = $values['rid'] . "-" . $_FILES['sgf']['name'];
        move_uploaded_file($_FILES['sgf']['tmp_name'], "sgf/" . $sgf);
        chmod("sgf/" . $sgf, 0777);
        $db_values['sgf'] = $sgf;
    }
    if ($insert)
        insert_row("results", $db_values);
    else
        update_rows("results", $db_values, "pw='$pw' and pb='$pb' and rid='$rid'");
}

?>
