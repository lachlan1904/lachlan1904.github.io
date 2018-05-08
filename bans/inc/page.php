<?php

class Page {
    public function __construct($name, $header = true) {
//        ini_set('mbstring.internal_encoding', 'UTF-8');
        ini_set('default_charset', 'utf-8');
        require_once './inc/settings.php';
        $settings = new Settings();
        setlocale(LC_ALL, $settings->lang);

        require_once './lang/en_US.utf8.php';
        $this->defaultlang = new DefaultLang();

        require_once './lang/' . $settings->lang . '.php';
        if (class_exists("Lang")) {
            $this->lang = new Lang();
        } else {
            $this->lang = $this->defaultlang;
        }

        $this->time = microtime(true);
        if ($header) {
            require_once './inc/header.php';
        }
        $this->conn = $settings->conn;
        $this->settings = $settings;
        $this->uuid_name_cache = array();
        $this->page = 1;
        if (isset($_GET['page'])) {
            $page = $_GET['page']; // user input
            if (filter_var($page, FILTER_VALIDATE_INT)) {
                $this->page = max(0, (int)$page);
            }
        }
        $this->name = $name;

        $this->type = null;
        $this->table = null;
        $this->title = null;

        $info = $this->type_info($name);
        $this->set_info($info);

        $this->permanent = array(
            'ban'  => $this->t("generic.permanent") . " " . $this->t("generic.ban"),
            'mute' => $this->t("generic.permanent") . " " . $this->t("generic.mute"),
            'warn' => $this->t("generic.permanent"),
            'kick' => null,
        );
        $this->expired = array(
            'ban'  => $this->t("page.expired.ban"),
            'mute' => $this->t("page.expired.mute"),
            'warn' => $this->t("page.expired.warning"),
            'kick' => null,
        );
        $this->expired_by = array(
            'ban'  => $this->t("page.expired.ban-by"),
            'mute' => $this->t("page.expired.mute-by"),
            'warn' => $this->t("page.expired.warning"),
            'kick' => null,
        );
        $this->punished_by = array(
            'ban'  => $this->t("generic.banned.by"),
            'mute' => $this->t("generic.muted.by"),
            'warn' => $this->t("generic.warned.by"),
            'kick' => $this->t("generic.kicked.by"),
        );

        if ($header) {
            $h = new Header($this);
            $this->header = $h;
            $h->print_header();
        }
        $this->table_headers_printed = false;
    }

    public function t($str) {
        if (array_key_exists($str, $this->lang->array)) {
            return $this->lang->array[$str];
        }
        if (array_key_exists($str, $this->defaultlang->array)) {
            return $this->defaultlang->array[$str];
        }
//        return "404";
        return $str;
    }

    public function type_info($type) {
        $settings = $this->settings;
        switch ($type) {
            case "ban":
            case "bans":
                return array(
                    "type"  => "ban",
                    "table" => $settings->table['bans'],
                    "title" => $this->t("title.bans"),
                );
            case "mute":
            case "mutes":
                return array(
                    "type"  => "mute",
                    "table" => $settings->table['mutes'],
                    "title" => $this->t("title.mutes"),
                );
            case "warn":
            case "warnings":
                return array(
                    "type"  => "warn",
                    "table" => $settings->table['warnings'],
                    "title" => $this->t("title.warnings"),
                );
            case "kick":
            case "kicks":
                return array(
                    "type"  => "kick",
                    "table" => $settings->table['kicks'],
                    "title" => $this->t("title.kicks"),
                );
            default:
                return array(
                    "type"  => null,
                    "table" => null,
                    "title" => null,
                );
        }
    }

    /**
     * @param $info
     */
    function set_info($info) {
        $this->type = $info['type'];
        $this->table = $info['table'];
        $this->title = $info['title'];
    }

    function run_query() {
        try {
            $table = $this->table;
            $limit = $this->settings->limit_per_page;

            $offset = 0;
            if ($this->settings->show_pager) {
                $page = $this->page - 1;
                $offset = ($limit * $page);
            }

            $sel = $this->get_selection($table);

            $where = $this->where_append($this->name === "kicks" ? "" : $this->settings->active_query);
            $where .= "(uuid <> '#offline#' AND uuid IS NOT NULL)";

            $query = "SELECT $sel FROM $table $where GROUP BY $table.id ORDER BY time DESC LIMIT :limit OFFSET :offset";
            $st = $this->conn->prepare($query);

            $st->bindParam(':offset', $offset, PDO::PARAM_INT);
            $st->bindParam(':limit', $limit, PDO::PARAM_INT);

            $st->execute();

            $rows = $st->fetchAll();

            $st->closeCursor();

            return $rows;
        } catch (PDOException $ex) {
            Settings::handle_error($this->settings, $ex);
        }
    }

    function get_selection($table) {
        // Under certain versions of PHP, there is a bug with BIT columns.
        // An empty string is returned no matter what the value is.
        // Workaround: cast to unsigned.
        $selection = "id,uuid,reason,banned_by_name,banned_by_uuid,time,until,server_origin,server_scope,CAST(active AS UNSIGNED) AS active,CAST(ipban AS UNSIGNED) AS ipban";
        if ($table === $this->settings->table['warnings']) {
            $selection .= ",CAST(warned AS UNSIGNED) AS warned";
        }
        if ($table !== $this->settings->table['kicks']) {
            $selection .= ",removed_by_uuid,removed_by_name,removed_by_date";
        }
        return $selection;
    }

    /**
     * Returns HTML representing the Minecraft avatar for a specific name or UUID.
     * @return string
     */
    function get_avatar($name, $uuid, $name_under = true, $name_repl = null, $name_left = true) {
        if ($name_under) {
            $name_under = $this->settings->avatar_names_below;
        }
        $avatar_source = $this->settings->avatar_source;

        if (strlen($uuid) === 36 && $uuid[14] === '3') {
            $avatar_source = $this->settings->avatar_source_offline_mode;
            // Avatars cannot be associated with offline mode UUIDs (version 3)
            if (!$this->settings->avatar_allow_offline_mode_uuids) {
                $uuid = $name;
            }
        }

        $uuid = str_replace("-", "", $uuid);
        $src = str_replace('{name}', $name, str_replace('{uuid}', $uuid, $avatar_source));
        if (in_array($name, $this->settings->console_aliases) || $name === $this->settings->console_name) {
            $src = $this->settings->console_image;
            $name = $this->settings->console_name;
        }
        if ($name_repl !== null) {
            $name = $name_repl;
        }
        $img = "<img class='avatar noselect' src='$src'/>";
        $str = "{$img}$name";
        if ($name_under) {
            $str = "{$img}<br class='noselect'>$name";
            return "<p align='center'>$str</p>";
        }
        if ($name_left) {
            return "<p align='left'>$str</p>";
        }
        return $str;
    }

    /**
     * Returns the banner name for a specific row in the database
     * using their UUID->name if possible, otherwise returns their last recorded name.
     * @param row
     * @return string
     */
    function get_banner_name($row) {
        $uuid = $row['banned_by_uuid'];
        $display_name = $row['banned_by_name'];
        $console_aliases = $this->settings->console_aliases;
        if (in_array($uuid, $console_aliases) || in_array($row['banned_by_name'], $console_aliases)) {
            return $this->settings->console_name;
        }
        $name = $this->get_name($uuid);
        if ($name !== null) {
            return $name;
        }
        return $this->clean($display_name);
    }

    /**
     * Returns the last name for a UUID, or null if their name is not recorded in the database.
     * @param string
     * @return null|string
     */
    function get_name($uuid) {
        if ($uuid === null || $uuid === "" || strrpos($uuid, "#", -strlen($uuid)) !== false) return null;
        if (in_array($uuid, $this->settings->console_aliases)) {
            return $this->settings->console_name;
        }
        if (array_key_exists($uuid, $this->uuid_name_cache)) return $this->uuid_name_cache[$uuid];

        $result = null;
        $history = $this->settings->table['history'];

        $stmt = $this->conn->prepare("SELECT name FROM $history WHERE uuid=? ORDER BY date DESC LIMIT 1");
        if ($stmt->execute(array($uuid)) && $row = $stmt->fetch()) {
            $result = $row['name'];
        }
        $stmt->closeCursor();

        $this->uuid_name_cache[$uuid] = $result;
        return $result;
    }

    /**
     * Prepares text to be displayed on the web interface.
     * Removes chat colours, replaces newlines with proper HTML, and sanitizes the text.
     * @param string
     * @return string|null
     */
    function clean($text) {
        if ($text === null) return null;
        if (strstr($text, "\xa7") || strstr($text, "&")) {
            $text = preg_replace("/(?i)(\x{00a7}|&)[0-9A-FK-OR]/u", "", $text);
        }
        $text = htmlspecialchars($text, ENT_QUOTES, "UTF-8");
        if (strstr($text, "\n")) {
            $text = preg_replace("/\n/", "<br>", $text);
        }
        return $text;
    }

    function server($row, $col = "server_scope") {
        $server = $this->clean($row[$col]);
        if ($server === null) {
            return "-";
        }
        if ($server === "*") {
            return $this->t("table.server.global");
        }
        return $server;
    }

    /**
     * Returns a string that shows the expiry date of a punishment.
     * If the punishment does not expire, it will be shown as permanent.
     * If the punishment has already expired, it will show as expired.
     * @param row
     * @return string
     */
    function expiry($row) {
        if ($this->type === "kick") {
            return "N/A";
        }
        if ($row['until'] <= 0) {
            $until = $this->permanent[$this->type];
        } else {
            $until = $this->millis_to_date($row['until']);
        }
        $expired = $this->is_expired($row);
        if ($this->active($row) === false) {

            $done = false;

            // Unbanned by $name
            $removed_by_uuid = $row['removed_by_uuid'];
            if ($removed_by_uuid !== null) {
                // Player has been unbanned

                // Check if uuid can be converted to name
                $name = $this->get_name($removed_by_uuid);
                if ($name === null) {
                    // Couldn't find name in history table, use removed_by_name instead
                    $name = $this->clean($row['removed_by_name']);
                }
                if ($name !== null) {
                    $until .= ' ' . str_replace('{name}', $name, $this->expired_by[$this->type]);
                    $done = true;
                }
            }
            if ($expired) {
                $done = true;
            }
            if ($done === false) {
                $until .= ' ' . $this->expired[$this->type];
            }
        }
        if ($expired) {
            $until .= ' ';
            $until .= $this->t("page.expired.warning");
        }
        return $until;
    }

    /**
     * Converts a timestamp (in milliseconds) to a date using the configured date format.
     * @param int
     * @return string
     */
    function millis_to_date($millis) {
        $ts = $millis / 1000;
        $result = strftime($this->settings->date_format, $ts);
        $translations = $this->settings->date_month_translations;
        if ($translations !== null) {
            foreach ($translations as $key => $val) {
                $result = str_replace($key, $val, $result);
            }
        }
        return $result;
    }

    function active($row, $field = 'active') {
        return (((int)$row[$field]) !== 0);
    }

    function is_expired($row) {
        $removed_by_name = $row['removed_by_name'];
        if ($removed_by_name === "#expired") return true;
        if ($removed_by_name !== null && $removed_by_name !== "") return false;

        $until = $row['until'];

        if ($until <= 0) return false;

        $time = gettimeofday();
        $millis = $time["sec"] * 1000;
//        $dtz = new DateTimeZone(date_default_timezone_get());
//        $dt = new DateTime("now", $dtz);
//        $millis = ($dt->getTimestamp() + $dt->getOffset()) * 1000;

        if ($millis > $until) {
            return true;
        }
        return false;
    }

    function print_title() {
        $title = $this->title;
        $name = $this->settings->name;
        if ($title === null) {
            echo "<title>$name</title>";
        } else {
            echo "<title>$title - $name</title>";
        }
    }

    function print_table_rows($row, $array, $print_headers = true) {
        $type = $this->type;
        if (!$this->settings->show_server_scope) {
            unset($array["server.name"]);
        }
        if ($print_headers && !$this->table_headers_printed) {
            $headers = array_keys($array);
            $headers_translated = array();
            foreach ($headers as $header) {
                if ($header === "executor" && $this->name !== "history") {
                    $header = $this->punished_by[$type];
                } else {
                    $header = $this->t("table." . $header);
                }
                array_push($headers_translated, $header);
            }
            $this->table_print_headers($headers_translated);
            $this->table_headers_printed = true;
        }
        $id = $row['id'];
        echo "<tr>";
        foreach ($array as $header => $text) {
            $a = "a";
            if ($header === "received-warning") {
                $icon = ($text !== "0") ? "glyphicon-ok" : "glyphicon-remove";
                $a .= " class=\"glyphicon $icon\" aria-hidden=true";
                $text = "";
            }
            echo "<td><$a href=\"info.php?type=$type&id=$id\">$text</a></td>";
        }
        echo "</tr>";
    }

    function table_print_headers($headers) {
        echo "<thead><tr>";
        foreach ($headers as $header) {
            echo "<th>$header</th>";
        }
        echo "<tbody>";
    }

    function print_header($container_start = true, $title = null, $class = "modal-header litebans-header") {
        if ($title === null) {
            $title = $this->title;
        }
        if ($container_start) {
            echo '<div class="container">';
        }

        echo "<div class=\"row\"><div class=\"col-lg-12\"><h1 class=\"$class\">$title</h1></div>";
        if ($container_start) {
            echo '</div><div class="row"><div class="col-lg-12">';
        }
    }

    function print_check_form() {
        $table = $this->name;
        echo '
         <div class="row litebans-check">
             <div class="litebans-check litebans-check-form">
                 <form onsubmit="captureForm(event);" class="form-inline"><div class="form-group"><input type="text" class="form-control" id="user" placeholder="' . $this->t("generic.player-name") . '"></div><button type="submit" class="btn btn-primary" style="margin-left: 5px;">' . $this->t("action.check") . '</button></form>
             </div>
             <script type="text/javascript">function captureForm(b){var o=$(".litebans-check-output");o.removeClass("show");var x=setTimeout(function(){o.html("<br>")}, 150);$.ajax({type:"GET",url:"check.php?name="+$("#user").val()+"&table=' . $table . '"}).done(function(c){clearTimeout(x);o.html(c);o.addClass("show")});b.preventDefault();return false};</script>
         </div>
         <div class="litebans-check litebans-check-output fade" class="success fade" data-alert="alert"></div>
         <br>
         ';
    }

    function print_pager($total = -1, $args = "", $prevargs = "") {
        if (!$this->settings->show_pager) return;
        $table = $this->table;
        $page = $this->name . ".php";

        if ($total === -1) {
            $where = $this->where_append($this->name === "kicks" ? "" : $this->settings->active_query);
            $where .= "(uuid <> '#offline#' AND uuid IS NOT NULL)";

            $st = $this->conn->query("SELECT COUNT(*) AS count FROM $table $where");
            $result = $st->fetch(PDO::FETCH_ASSOC);
            $st->closeCursor();
            $total = $result['count'];
        }

        $pages = (int)($total / $this->settings->limit_per_page) + 1;

        $cur = $this->page;
        $prev = $cur - 1;
        $next = $this->page + 1;

        $prev_active = ($cur > 1);
        $next_active = ($cur < $pages);

        $prev_class = "litebans-" . ($prev_active ? "pager-active" : "pager-inactive");
        $next_class = "litebans-" . ($next_active ? "pager-active" : "pager-inactive");

        $pager_prev = "<div class=\"litebans-pager litebans-pager-left $prev_class\">«</div>";
        if ($prev_active) {
            $pager_prev = "<a href=\"$page?page={$prev}{$prevargs}\">$pager_prev</a>";
        }

        $pager_next = "<div class=\"litebans-pager litebans-pager-right $next_class\">»</div>";
        if ($next_active) {
            $pager_next = "<a href=\"$page?page={$next}{$args}\">$pager_next</a>";
        }
        $pager_count = '<div><div class="litebans-pager-number">' . $this->t("table.pager.number") . ' ' . $cur . '/' . $pages . '</div></div>';
        echo "$pager_prev $pager_next $pager_count";
    }

    function print_footer($container_end = true) {
        if ($container_end) {
            echo "</div></div></div>";
        }
        $time = microtime(true) - $this->time;
        echo "<!-- Page generated in $time seconds. -->";

        include_once './inc/footer.php';
    }

    function autoversion($file) {
        return $file . "?" . filemtime($file);
    }

    function table_begin() {
        echo '<table class="table table-striped table-bordered table-condensed">';
    }

    function table_end($clicky = true) {
        echo '</table>';
        if ($clicky) {
            echo "<script type=\"text/javascript\">withjQuery(function(){ $('tr').click(function(){var href=$(this).find('a').attr('href');if(href!==undefined)window.location=href;}).hover(function(){\$(this).toggleClass('hover');}); });</script>";
        }
    }

    /**
     * lcfirst is only supported in PHP >= 5.3
     * @param $str
     * @return string
     */
    static function lc_first($str) {
        $str[0] = strtolower($str[0]);
        return (string)$str;
    }

    /**
     * @param $where
     * @return string
     */
    public function where_append($where) {
        if ($where !== "") {
            return $where . " AND ";
        } else {
            return "WHERE ";
        }
    }
}
