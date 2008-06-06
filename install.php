<?php
	define('MAIN_DIR', dirname(__FILE__));
	define('INCLUDES_DIR', MAIN_DIR."/includes");
	define('DEBUG', true);
	define('JAVASCRIPT', false);
	define('ADMIN', false);
	define('AJAX', false);
	define('XML_RPC', false);
	ini_set('error_reporting', E_ALL);
	ini_set('display_errors', true);

	require_once INCLUDES_DIR."/class/QueryBuilder.php"; # SQL query builder
	require_once INCLUDES_DIR."/lib/spyc.php"; # YAML parser
	require_once INCLUDES_DIR."/class/Trigger.php";
	require_once INCLUDES_DIR."/class/Model.php";
	require_once INCLUDES_DIR."/model/User.php";
	require_once INCLUDES_DIR."/model/Visitor.php";
	require_once INCLUDES_DIR."/class/Session.php"; # Session handler

	# Configuration files
	require INCLUDES_DIR."/config.php";
	require INCLUDES_DIR."/database.php";

	# Translation stuff
	require INCLUDES_DIR."/lib/gettext/gettext.php";
	require INCLUDES_DIR."/lib/gettext/streams.php";

	# Helpers
	require INCLUDES_DIR."/helpers.php";

	sanitize_input($_GET);
	sanitize_input($_POST);
	sanitize_input($_COOKIE);
	sanitize_input($_REQUEST);

	$url = "http://".$_SERVER['HTTP_HOST'].str_replace("/install.php", "", $_SERVER['REQUEST_URI']);
	$index = (parse_url($url, PHP_URL_PATH)) ? "/".trim(parse_url($url, PHP_URL_PATH), "/")."/" : "/" ;
	$htaccess = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase ".str_replace("install.php", "", $index)."\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^.+$ index.php [L]\n</IfModule>";

	$errors = array();
	$installed = false;

	if (file_exists(INCLUDES_DIR."/config.yaml.php") and file_exists(INCLUDES_DIR."/database.yaml.php") and file_exists(MAIN_DIR."/.htaccess")) {
		$sql->load(INCLUDES_DIR."/database.yaml.php");
		$config->load(INCLUDES_DIR."/config.yaml.php");

		if ($sql->connect(true) and !empty($config->url) and $sql->query("select count(`id`) from `__users`")->fetchColumn())
			error(__("Already Installed"), __("Chyrp is already correctly installed and configured."));
	} else {
		if (!is_writable(MAIN_DIR) and (!file_exists(MAIN_DIR."/.htaccess") or !preg_match("/".preg_quote($htaccess, "/")."/", file_get_contents(MAIN_DIR."/.htaccess"))))
			$errors[] = sprintf(__("STOP! Before you go any further, you must create a .htaccess file in Chyrp's install directory and put this in it:\n<pre>%s</pre>."), htmlspecialchars($htaccess));

		if (!is_writable(INCLUDES_DIR))
			$errors[] = __("Chyrp's includes directory is not writable by the server.");
	}

	if (!empty($_POST)) {
		if (($_POST['adapter'] == "sqlite" or $_POST['adapter'] == "sqlite2") and !is_writable(MAIN_DIR))
			$errors[] = __("SQLite database file could not be created. Please CHMOD your Chyrp directory to 777 and try again.");
		else
			if ($_POST['adapter'] == "mysql")
				try {
					new PDO($_POST['adapter'].":host=".$_POST['host'].";".((!empty($_POST['port'])) ? "port=".$_POST['port'].";" : "")."dbname=".$_POST['database'], $_POST['username'], $_POST['password']);
				} catch(PDOException $e) {
					$errors[] = __("Could not connect to the specified database.");
				}
			elseif ($_POST['adapter'] == "sqlite" or $_POST['adapter'] == "sqlite2")
				try {
					new PDO($_POST['adapter'].":".MAIN_DIR."/chyrp.db");
				} catch(PDOException $e) {
					$errors[] = __("Could not connect to specified database.");
				}

		if (empty($_POST['name']))
			$errors[] = __("Please enter a name for your website.");

		if (!isset($_POST['time_offset']))
			$errors[] = __("Time offset cannot be blank.");

		if (empty($_POST['login']))
			$errors[] = __("Please enter a username for your account.");

		if (empty($_POST['password_1']))
			$errors[] = __("Password cannot be blank.");

		if ($_POST['password_1'] != $_POST['password_2'])
			$errors[] = __("Passwords do not match.");

		if (empty($_POST['email']))
			$errors[] = __("E-Mail address cannot be blank.");

		if (empty($errors)) {
			$sql->set("host", $_POST['host']);
			$sql->set("username", $_POST['username']);
			$sql->set("password", $_POST['password']);
			$sql->set("database", ($_POST['adapter'] == "sqlite" or $_POST['adapter'] == "sqlite2") ? MAIN_DIR."/chyrp.db" : $_POST['database']);
			$sql->set("prefix", $_POST['prefix']);
			$sql->set("adapter", $_POST['adapter']);

			$sql->prefix = $_POST['prefix'];

			$sql->connect();

			# Posts table
			$sql->query("CREATE TABLE IF NOT EXISTS `__posts` (
			                 `id` INTEGER PRIMARY KEY AUTO_INCREMENT,
			                 `xml` LONGTEXT DEFAULT '',
			                 `feather` VARCHAR(32) DEFAULT '',
			                 `clean` VARCHAR(128) DEFAULT '',
			                 `url` VARCHAR(128) DEFAULT '',
			                 `pinned` TINYINT(1) DEFAULT '0',
			                 `status` VARCHAR(32) DEFAULT 'public',
			                 `user_id` INTEGER DEFAULT '0',
			                 `created_at` DATETIME DEFAULT '0000-00-00 00:00:00',
			                 `updated_at` DATETIME DEFAULT '0000-00-00 00:00:00'
			             ) DEFAULT CHARSET=utf8");

			# Pages table
			$sql->query("CREATE TABLE IF NOT EXISTS `__pages` (
			                 `id` INTEGER PRIMARY KEY AUTO_INCREMENT,
			                 `title` VARCHAR(250) DEFAULT '',
			                 `body` LONGTEXT DEFAULT '',
			                 `show_in_list` TINYINT(1) DEFAULT '1',
			                 `list_order` INTEGER DEFAULT '0',
			                 `clean` VARCHAR(128) DEFAULT '',
			                 `url` VARCHAR(128) DEFAULT '',
			                 `user_id` INTEGER DEFAULT '0',
			                 `parent_id` INTEGER DEFAULT '0',
			                 `created_at` DATETIME DEFAULT '0000-00-00 00:00:00',
			                 `updated_at` DATETIME DEFAULT '0000-00-00 00:00:00'
			             ) DEFAULT CHARSET=utf8");

			# Users table
			$sql->query("CREATE TABLE IF NOT EXISTS `__users` (
			                 `id` INTEGER PRIMARY KEY AUTO_INCREMENT,
			                 `login` VARCHAR(64) DEFAULT '',
			                 `password` VARCHAR(32) DEFAULT '',
			                 `full_name` VARCHAR(250) DEFAULT '',
			                 `email` VARCHAR(128) DEFAULT '',
			                 `website` VARCHAR(128) DEFAULT '',
			                 `group_id` INTEGER DEFAULT '0',
			                 `joined_at` DATETIME DEFAULT '0000-00-00 00:00:00',
			                 UNIQUE (`login`)
			             ) DEFAULT CHARSET=utf8");

			# Groups table
			$sql->query("CREATE TABLE IF NOT EXISTS `__groups` (
			                 `id` INTEGER PRIMARY KEY AUTO_INCREMENT,
			                 `name` VARCHAR(100) DEFAULT '',
		                     `permissions` LONGTEXT DEFAULT '',
			                 UNIQUE (`name`)
			             ) DEFAULT CHARSET=utf8");

			# Permissions table
			$sql->query("CREATE TABLE IF NOT EXISTS `__permissions` (
			                 `id` INTEGER PRIMARY KEY AUTO_INCREMENT,
			                 `name` VARCHAR(100) DEFAULT '',
			                 UNIQUE (`name`)
			             ) DEFAULT CHARSET=utf8");

			# Sessions table
			$sql->query("CREATE TABLE IF NOT EXISTS `__sessions` (
			                 `id` VARCHAR(32) DEFAULT '',
			                 `data` LONGTEXT DEFAULT '',
			                 `user_id` VARCHAR(16) DEFAULT '0',
			                 `created_at` DATETIME DEFAULT '0000-00-00 00:00:00',
			                 `updated_at` DATETIME DEFAULT '0000-00-00 00:00:00',
			                 PRIMARY KEY (`id`)
			             ) DEFAULT CHARSET=utf8");

			$permissions = array("view_site",
			                     "change_settings",
			                     "toggle_extensions",
			                     "add_post",
			                     "add_draft",
			                     "edit_post",
			                     "edit_own_post",
			                     "edit_draft",
			                     "edit_own_draft",
			                     "delete_post",
			                     "delete_own_post",
			                     "delete_draft",
			                     "delete_own_draft",
			                     "view_private",
			                     "view_draft",
			                     "add_page",
			                     "edit_page",
			                     "delete_page",
			                     "add_user",
			                     "edit_user",
			                     "delete_user",
			                     "add_group",
			                     "edit_group",
			                     "delete_group");

			foreach ($permissions as $permission)
				$sql->insert("permissions", array("name" => ":permission"), array(":permission" => $permission));

			$groups = array(
				"admin" => Spyc::YAMLDump($permissions),
				"member" => Spyc::YAMLDump(array("view_site")),
				"friend" => Spyc::YAMLDump(array("view_site", "view_private")),
				"banned" => Spyc::YAMLDump(array()),
				"guest" => Spyc::YAMLDump(array("view_site"))
			);

			# Insert the default groups (see above)
			foreach($groups as $name => $permissions)
				$sql->insert("groups", array("name" => ":name", "permissions" => ":permissions"), array(":name" => ucfirst($name), ":permissions" => $permissions));

			if (!file_exists(MAIN_DIR."/.htaccess"))
				if (!@file_put_contents(MAIN_DIR."/.htaccess", $htaccess))
					$errors[] = __("Could not generate .htaccess file. Clean URLs will not be available.");
			elseif (file_exists(MAIN_DIR."/.htaccess") and !preg_match("/".preg_quote($htaccess, "/")."/", file_get_contents(MAIN_DIR."/.htaccess")))
				if (!@file_put_contents(MAIN_DIR."/.htaccess", "\n\n".$htaccess, FILE_APPEND))
					$errors[] = __("Could not generate .htaccess file. Clean URLs will not be available.");

			$config->set("name", $_POST['name']);
			$config->set("description", $_POST['description']);
			$config->set("url", "");
			$config->set("chyrp_url", $url);
			$config->set("email", $_POST['email']);
			$config->set("locale", "en_US");
			$config->set("theme", "default");
			$config->set("posts_per_page", 5);
			$config->set("feed_items", 20);
			$config->set("clean_urls", false);
			$config->set("post_url", "(year)/(month)/(day)/(url)/");
			$config->set("time_offset", $_POST['time_offset'] * 3600);
			$config->set("can_register", true);
			$config->set("default_group", 2);
			$config->set("guest_group", 5);
			$config->set("enable_trackbacking", true);
			$config->set("send_pingbacks", false);
			$config->set("secure_hashkey", md5(random(32, true)));
			$config->set("uploads_path", "/uploads/");
			$config->set("enabled_modules", array());
			$config->set("enabled_feathers", array("text"));
			$config->set("routes", array());

			$config->load(INCLUDES_DIR."/config.yaml.php");

			if (!$sql->select("users", "id", "`__users`.`login` = :login", null, array(":login" => $_POST['login']))->fetchColumn())
				$sql->insert("users",
				             array("login" => ":login",
				                   "password" => ":password",
				                   "email" => ":email",
				                   "website" => ":website",
				                   "group_id" => ":group_id",
				                   "joined_at" => ":joined_at"),
				             array(":login" => $_POST['login'],
				                   ":password" => md5($_POST['password_1']),
				                   ":email" => $_POST['email'],
				                   ":website" => $config->url,
				                   ":group_id" => 1,
				                   ":joined_at" => datetime()
				             ));

			session_set_save_handler(array("Session", "open"),
			                         array("Session", "close"),
			                         array("Session", "read"),
			                         array("Session", "write"),
			                         array("Session", "destroy"),
			                         array("Session", "gc"));
			session_set_cookie_params(60 * 60 * 24 * 30);
			session_name(sanitize(camelize($config->name), false, true));
			session_start();

			$_SESSION['chyrp_login'] = $_POST['login'];
			$_SESSION['chyrp_password'] = md5($_POST['password_1']);

			$installed = true;
		}
	}

	function value_fallback($index, $fallback = "") {
		echo (isset($_POST[$index])) ? $_POST[$index] : $fallback ;
	}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-type" content="text/html; charset=utf-8" />
		<title>Chyrp Installer</title>
		<style type="text/css" media="screen">
			body {
				font: .8em/1.5em normal "Lucida Grande", "Trebuchet MS", Verdana, Helvetica, Arial, sans-serif;
				color: #333;
				background: #eee;
				margin: 0;
				padding: 0;
			}
			a {
				color: #0088FF;
			}
			h1 {
				font-size: 1.75em;
				margin-top: 0;
				color: #aaa;
				font-weight: bold;
			}
			h2 {
				font-size: 1.25em;
				font-weight: bold;
			}
			ol {
				margin: 0 0 1em;
				padding: 0 0 0 2em;
			}
			label {
				display: block;
				font-weight: bold;
				border-bottom: 1px dotted #ddd;
				margin-bottom: 2px;
			}
			input[type="password"], input[type="text"], textarea, select {
				font-size: 1.25em;
				width: 242px;
				padding: 3px;
				border: 1px solid #ddd;
			}
			textarea {
				margin-bottom: .75em;
			}
			form hr {
				border: 0;
				padding-bottom: 1em;
				margin-bottom: 3em;
				border-bottom: 1px dashed #ddd;
			}
			form p {
				padding-bottom: 1em;
			}
			form p.extra {
				padding-bottom: 2em;
			}
			.window {
				width: 250px;
				margin: 25px auto;
				padding: 1em;
				border: 1px solid #ddd;
				background: #fff;
			}
			.sub {
				font-size: .8em;
				color: #777;
				font-weight: normal;
			}
			.sub.inline {
				float: left;
				margin-top: -1.5em !important;
			}
			.center {
				text-align: center;
				padding: 0;
				margin-bottom: 1em;
				border: 0;
			}
			.error {
				padding: 6px 8px 5px 30px;
				border-bottom: 1px solid #FBC2C4;
				color: #D12F19;
				background: #FBE3E4 url('./admin/icons/failure.png') no-repeat 7px center;
			}
			.error.last {
				margin: 0 0 1em 0;
			}
			.done {
				font-size: 1.25em;
				font-weight: bold;
				text-decoration: none;
				color: #555;
			}
		</style>
		<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.2.6/jquery.min.js" type="text/javascript" charset="utf-8"></script>
		<script type="text/javascript">
			$(function(){
				$("#adapter").change(function(){
					if ($(this).val() == "sqlite" || $(this).val() == "sqlite2")
						$("#host_field, #username_field, #password_field, #database_field").animate({ height: "hide", opacity: "hide" })
					else
						$("#host_field, #username_field, #password_field, #database_field").show()
				})
			})
		</script>
	</head>
	<body>
<?php foreach ($errors as $index => $error): ?>
		<div class="error<?php if ($index + 1 == count($errors)) echo " last"; ?>"><?php echo $error; ?></div>
<?php endforeach; ?>
		<div class="window">
<?php if (!$installed): ?>
			<form action="install.php" method="post" accept-charset="utf-8">
				<h1><?php echo __("Database Setup"); ?></h1>
				<p id="adapter_field">
					<label for="adapter"><?php echo __("Adapter"); ?></label>
					<select name="adapter" id="adapter">
						<option value="mysql" selected="selected">MySQL</option>
						<option value="sqlite">SQLite</option>
						<option value="sqlite2">SQLite 2</option>
					</select>
				</p>
				<p id="host_field">
					<label for="host"><?php echo __("Host"); ?> <span class="sub"><?php echo __("(usually ok as \"localhost\")"); ?></span></label>
					<input type="text" name="host" value="<?php value_fallback("host", ((isset($_ENV['DATABASE_SERVER'])) ? $_ENV['DATABASE_SERVER'] : "localhost")); ?>" id="host" />
				</p>
				<p id="username_field">
					<label for="username"><?php echo __("Username"); ?></label>
					<input type="text" name="username" value="<?php value_fallback("username"); ?>" id="username" />
				</p>
				<p id="password_field">
					<label for="password"><?php echo __("Password"); ?></label>
					<input type="password" name="password" value="<?php value_fallback("password"); ?>" id="password" />
				</p>
				<p id="database_field">
					<label for="database"><?php echo __("Database"); ?></label>
					<input type="text" name="database" value="<?php value_fallback("database"); ?>" id="database" />
				</p>
				<p id="prefix_field">
					<label for="prefix"><?php echo __("Table Prefix"); ?> <span class="sub"><?php echo __("(optional)"); ?></span></label>
					<input type="text" name="prefix" value="<?php value_fallback("prefix"); ?>" id="prefix" />
				</p>
				<hr />
				<h1><?php echo __("Website Setup"); ?></h1>
				<p id="name_field">
					<label for="name"><?php echo __("Site Name"); ?></label>
					<input type="text" name="name" value="<?php value_fallback("name", __("My Awesome Site")); ?>" id="name" />
				</p>
				<p id="description_field">
					<label for="description"><?php echo __("Description"); ?></label>
					<textarea name="description" rows="2" cols="40"><?php value_fallback("description"); ?></textarea>
				</p>
				<p id="time_offset_field" class="extra">
					<label for="time_offset"><?php echo __("Time Offset"); ?></label>
					<input type="text" name="time_offset" value="0" id="time_offset" />
					<span class="sub inline">(server time: <?php echo @date("F jS, Y g:i A"); ?>)</span>
				</p>

				<h1><?php echo __("Admin Account"); ?></h1>
				<p id="login_field">
					<label for="login"><?php echo __("Username"); ?></label>
					<input type="text" name="login" value="<?php value_fallback("login", "Admin"); ?>" id="login" />
				</p>
				<p id="password_1_field">
					<label for="password_1"><?php echo __("Password"); ?></label>
					<input type="password" name="password_1" value="<?php value_fallback("password_1"); ?>" id="password_1" />
				</p>
				<p id="password_2_field">
					<label for="password_2"><?php echo __("Password"); ?> <span class="sub"><?php echo __("(again)"); ?></span></label>
					<input type="password" name="password_2" value="<?php value_fallback("password_2"); ?>" id="password_2" />
				</p>
				<p id="email_field">
					<label for="email"><?php echo __("E-Mail Address"); ?></label>
					<input type="text" name="email" value="<?php value_fallback("email"); ?>" id="email" />
				</p>

				<p class="center"><input type="submit" value="<?php echo __("Install!"); ?>"></p>
			</form>
<?php else: ?>
			<h1><?php echo __("Done!"); ?></h1>
			<p>
				<?php echo __("Chyrp has been successfully installed."); ?>
			</p>
			<h2>So, what now?</h2>
			<ol>
				<li><?php echo __("<strong>Delete install.php</strong>, you won't need it anymore."); ?></li>
				<li><a href="http://chyrp.net/extend/browse/translations"><?php echo __("Look for a translation for your language."); ?></a></li>
				<li><a href="http://chyrp.net/extend/browse/modules"><?php echo __("Install some Modules."); ?></a></li>
				<li><a href="http://chyrp.net/extend/browse/feathers"><?php echo __("Find some Feathers you want."); ?></a></li>
				<li><a href="getting_started.html"><?php echo __("Read &#8220;Getting Started&#8221;"); ?></a></li>
			</ol>
			<a class="done" href="<?php echo $config->url; ?>"><?php echo __("Take me to my site! &rarr;"); ?></a>
<?php
	endif;
?>
		</div>
	</body>
</html>
