<?php
/**
 * The official Revisr WordPress plugin.
 *
 * A plugin that allows developers to manage WordPress websites with Git repositories.
 * Integrates several key git functions into the WordPress admin.
 *
 * @package   Revisr
 * @author    Matt Shaw <matt@expandedfronts.com>
 * @license   GPL-2.0+
 * @link      https://revisr.io
 * @copyright 2014 Expanded Fronts, LLC
 *
 * Plugin Name:       Revisr
 * Plugin URI:        https://revisr.io/
 * Description:       A plugin that allows developers to manage WordPress websites with Git repositories.
 * Version:           1.0.0
 * Author:            Expanded Fronts, LLC
 * Author URI:        http://expandedfronts.com/
 */

include_once 'admin/includes/init.php';
include_once 'admin/includes/functions.php';

class Revisr
{

	public $wpdb;
	public $time;
	public $table_name;
	private $current_dir;
	private $current_branch;

	public function __construct()
	{
		//Declarations
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->table_name = $wpdb->prefix . "revisr";
		$this->time = current_time( 'mysql' );
		$init = new revisr_init;
		$this->current_dir = getcwd();
		$this->current_branch = exec("git rev-parse --abbrev-ref HEAD");

		//Git functions
		add_action( 'publish_revisr_commits', array($this, 'commit') );
		add_action( 'admin_post_revert', array($this, 'revert') );
		add_action( 'admin_post_checkout', array($this, 'checkout') );
		add_action( 'admin_post_view_diff', array($this, 'view_diff') );
		add_action( 'wp_ajax_new_commit', array($this, 'new_commit') );
		add_action( 'wp_ajax_discard', array($this, 'discard') );
		add_action( 'wp_ajax_push', array($this, 'push') );
		add_action( 'wp_ajax_pull', array($this, 'pull') );

		//Committed / pending files
		add_action( 'wp_ajax_pending_files', array($this, 'pending_files') );
		add_action( 'wp_ajax_committed_files', array($this, 'committed_files') );

		//Recent activity
		add_action( 'wp_ajax_recent_activity', array($this, 'recent_activity') );

		//Install
		register_activation_hook( __FILE__, array($this, 'revisr_install'));
	}

	public function commit()
	{
		$title = $_REQUEST['post_title'];
		$this->git("add -A");
		$this->git("commit -am '" . $title . "'");
		$commit_hash = $this->git("log --pretty=format:'%h' -n 1");
		$this->git("push origin {$this->current_branch}");
		add_post_meta( get_the_ID(), 'commit_hash', $commit_hash );
		$author = the_author();
		$view_link = get_admin_url() . "post.php?post=" . get_the_ID() . "&action=edit";
		$this->log("Committed <a href='{$view_link}'>#{$commit_hash[0]}</a> to the repository.", "commit");
		$this->notify(get_bloginfo() . " - New Commit", "A new commit was made to the repository:<br> #{$commit_hash[0]} - {$title}");
		return $commit_hash;
	}

	public function new_commit()
	{
		$url = get_admin_url() . "post-new.php?post_type=revisr_commits";
		wp_redirect($url);
	}

	//Reverts to a specified commit.
	public function revert()
	{
		$commit = $_GET['commit_hash'];
		$this->git("reset --hard {$commit}");
		$this->git("reset --soft HEAD@{1}");
		$this->git("add -A");
		$commit_hash = $this->git("push origin {$this->current_branch}");
		$this->git("commit -am 'Reverted to commit: #" . $commit . "'");
		$this->log("Reverted to commit #{$commit}.", "revert");
		$this->notify(get_bloginfo() . " - Commit Reverted", get_bloginfo() . " was reverted to commit #{$commit}.");
		$url = get_admin_url() . "admin.php?page=revisr&revert=success&commit={$commit}";
		wp_redirect($url);
	}

	public function discard()
	{
		$this->git("reset --hard HEAD");
		$this->log("Discarded all changes to the working directory.", "discard");
		$this->notify(get_bloginfo() . " - Changes Discarded", "All changes were discarded on " . get_bloginfo() . "." );
		echo "<p>Successfully discarded uncommitted changes.</p>";
		exit;
	}

	public function checkout()
	{
		$branch = $_REQUEST['branch'];
		$this->git("reset --hard HEAD");
		$this->git("checkout {$branch}");
		$this->log("Checked out branch: {$branch}.", "branch");
		$this->notify(get_bloginfo() . " - Branch Changed", get_bloginfo() . " was switched to the branch {$branch}.");
		$url = get_admin_url() . "admin.php?page=revisr&branch={$branch}&checkout=success";
		wp_redirect($url);
	}

	public function push()
	{
		$this->git("reset --hard HEAD");
		$this->git("push origin HEAD");
		$this->log("Pushed changes to the remote repository.", "push");
		$this->notify(get_bloginfo() . " - Changes Pushed", "Changes were pushed to the remote repository for " . get_bloginfo());
		echo "<p>Successfully pushed to the remote.</p>";
		exit;
	}

	public function pull()
	{
		$this->git("reset --hard HEAD");
		$this->git("pull origin");
		$this->log("Pulled changes from the remote repository", "pull");
		$this->notify(get_bloginfo() . " - Changes Pulled", "Changes were pulled from the remote repository for " . get_bloginfo());
		echo "<p>Successfully pulled from the remote.</p>";
		exit;
	}

	public function git($args)
	{
		$cmd = "git $args";
		chdir(ABSPATH);
		exec($cmd, $output);
		chdir($this->current_dir);
		return $output;
	}

	public static function committed_files()
	{
		$files = get_post_custom_values( 'committed_files', $_POST['id'] );
		foreach ( $files as $file ) {
		    $output = unserialize($file);
		}

		echo "<br><strong>" . count($output) . "</strong> files were included in this commit. (<a href='" . get_admin_url() . "admin.php?page=revisr'>view all</a>).<br><br>";

		if (isset($_POST['pagenum'])) {
			$current_page = $_POST['pagenum'];
		}
		else {
			$current_page = 1;
		}
		
		$num_rows = count($output);
		$rows_per_page = 20;
		$last_page = ceil($num_rows/$rows_per_page);

		if ($current_page < 1){
		    $current_page = 1;
		}
		if ($current_page > $last_page){
		    $current_page = $last_page;
		}
		
		$offset = $rows_per_page * ($current_page - 1);

		$results = array_slice($output, $offset, $rows_per_page);
		?>
		<table class="widefat">
			<thead>
			    <tr>
			        <th>File</th>
			        <th>Status</th>
			    </tr>
			</thead>
			<tbody>
			<?php
				//Clean up output from git status and echo the results.
				foreach ($results as $result) {
					$short_status = substr($result, 0, 3);
					$file = substr($result, 3);
					$status = get_status($short_status);
					echo "<tr><td>{$file}</td><td>{$status}</td></td>";
				}
			?>
			</tbody>
		</table>
		<?php
			if ($current_page != "1"){
				echo "<a href='#' onclick='prev1();return false;'><- Previous</a>";
			}
			echo " Page {$current_page} of {$last_page} "; 
			if ($current_page != $last_page){
				echo "<a href='#' onclick='next1();return false;'>Next -></a>";
			}
			exit();
	}

	public function pending_files()
	{
		$output = $this->git("status --short");

		echo "<br>There are <strong>" . count($output) . "</strong> pending files that will be added to this commit. (<a href='" . get_admin_url() . "admin.php?page=revisr'>view all</a>).<br><br>";

		$current_page = $_POST['pagenum'];
		$num_rows = count($output);
		$rows_per_page = 20;
		$last_page = ceil($num_rows/$rows_per_page);

		if ($current_page < 1){
		    $current_page = 1;
		}
		if ($current_page > $last_page){
		    $current_page = $last_page;
		}
		
		$offset = $rows_per_page * ($current_page - 1);

		$results = array_slice($output, $offset, $rows_per_page);
		?>
		<table class="widefat">
			<thead>
			    <tr>
			        <th>File</th>
			        <th>Status</th>
			    </tr>
			</thead>
			<tbody>
			<?php
				//Clean up output from git status and echo the results.
				foreach ($results as $result) {
					$short_status = substr($result, 0, 3);
					$file = substr($result, 3);
					$status = get_status($short_status);

					if ($status != "Untracked") {
						echo "<tr><td><a href='" . get_admin_url() . "admin.php?page=view_diff&file={$file}' target='_blank'>{$file}</a></td><td>{$status}</td></td>";
					}
					else {
						echo "<tr><td>{$file}</td><td>{$status}</td></td>";
					}
				}
			?>
			</tbody>
		</table>
		<?php
			if ($current_page != "1"){
				echo "<a href='#' onclick='prev();return false;'><- Previous</a>";
			}
			echo " Page {$current_page} of {$last_page} "; 
			if ($current_page != $last_page){
				echo "<a href='#' onclick='next();return false;'>Next -></a>";
			}
			exit();

	}

	//Displays on the plugin dashboard via AJAX.
	public function recent_activity()
	{
		global $wpdb;
		$revisr_events = $wpdb->get_results('SELECT * FROM ef_revisr ORDER BY id DESC LIMIT 10', ARRAY_A);

		foreach ($revisr_events as $revisr_event) {
			echo "<tr><td>{$revisr_event['message']}</td><td>{$revisr_event['time']}</td></tr>";
		}
		exit;
	}

	private function log($message, $event)
	{
		$this->wpdb->insert(
			"$this->table_name",
			array(
				"time" => $this->time,
				"message" => $message,
				"event" => $event
			),
			array(
				'%s',
				'%s',
				'%s'
			)
		);
	}

	private function notify($subject, $message)
	{
		$options = get_option('revisr_settings');
		$url = get_admin_url() . "admin.php?page=revisr";
		

		if (isset($options['notifications'])) {
			$email = $options['email'];
			$message .= "<br><br><a href='{$url}'>Click here</a> for more details.";
			$headers = "Content-Type: text/html; charset=ISO-8859-1\r\n";
			wp_mail($email, $subject, $message, $headers);
		}
	}

	public function revisr_install()
	{
		$sql = "CREATE TABLE IF NOT EXISTS $this->table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			message TEXT,
			event VARCHAR(42) NOT NULL,
			UNIQUE KEY id (id)
			);";
		
	  	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	   	dbDelta( $sql );
	   	add_option( "revisr_db_version", "1.0" );
	}		
}

$revisr = new Revisr;