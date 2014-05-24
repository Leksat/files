#!/usr/bin/php
<?php

if (php_sapi_name() != 'cli') {
  @ob_end_clean();
  exit;
}

define('DRUPAL_ROOT', dirname(__FILE__));
chdir(DRUPAL_ROOT);

color_echo('Updating git submodules...');
chdir(exec('git rev-parse --show-toplevel'));
exec('git submodule sync');
exec('git submodule update --init');
chdir(DRUPAL_ROOT);

include_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_DATABASE);

color_echo('Making search index read-only...');
exec('drush sqlq "UPDATE search_api_index SET read_only = 1"');
if (function_exists('apachesolr_load_all_environments') && function_exists('apachesolr_environment_save')) {
  // The case if the apachesolr module is used.
  foreach (apachesolr_load_all_environments() as $env) {
    $env["conf"]["apachesolr_read_only"] = APACHESOLR_READ_ONLY;
    apachesolr_environment_save($env);
  }
}

color_echo('Correcting domains and domain variants...');
try {
  // This is not really precise code... It tries to guess values based on our
  // usual practices.
  if (function_exists('db_select') && ($new_domain = preg_replace('#^https?://#', '', $base_url))) {
    $old_domain = db_select('domain', 'd')
        ->fields('d', array('subdomain'))
        ->orderBy('d.domain_id')
        ->execute()
        ->fetchField();
    db_query("UPDATE domain SET subdomain = '$new_domain' WHERE subdomain = '$old_domain'");
    db_query("UPDATE domain_variants SET path = REPLACE(path, '$old_domain', '$new_domain') WHERE INSTR(path, '$old_domain') > 0");
  }
}
catch (Exception $s) {}

color_echo('Enabling emails reroute...');
exec('drush en reroute_email -y');
variable_set('reroute_email_enable', 1);
variable_set('reroute_email_enable_message', 1);
variable_set('reroute_email_address', 'development@amazee.com');

color_echo('Clearing caches...');
exec('drush cc all');
    
color_echo('Start sync files...');
exec('drush -y rsync @web1:sites/default/files sites/default/files');

color_echo('DONE!');

function color_echo($text) {
  echo "\033[1;36m$text\033[0m
";
}
