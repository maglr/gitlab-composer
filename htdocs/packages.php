<?php
use Gitlab\Client;

//ini_set('display_errors', 'On');

require_once __DIR__ . '/../vendor/autoload.php';
require_once 'functions.php';

$cache_folder = __DIR__ . '/../cache/';
$packages_file = $cache_folder . 'packages.json';
$static_file = __DIR__ . '/../confs/static-repos.json';
$config_file = __DIR__ . '/../confs/gitlab.ini'; // See ../confs/samples/gitlab.ini
if (!file_exists($config_file)) {
    header('HTTP/1.0 500 Internal Server Error');
    die('confs/gitlab.ini missing');
}
$confs = parse_ini_file($config_file);
if (isset($confs['port'])) {
    define('PORT', $confs['port']);
} else {
    define('PORT', '22');
}

$validMethods = ['ssh', 'http'];
if (isset($confs['method']) && in_array($confs['method'], $validMethods)) {
    define('METHOD', $confs['method']);
} else {
    define('METHOD', 'ssh');
}

define('ALLOW_PACKAGE_NAME_MISMATCH', !empty($confs['allow_package_name_mismatch']));
define('HiDE_PROJECTS', !empty($confs['hide_projects']));


// Create api and do first api calls
$client = Client::create($confs['endpoint']);
$client->authenticate($confs['api_key'], Client::AUTH_URL_TOKEN);

$groups = $client->groups();
$projects = $client->projects();
$repos = $client->repositories();

clear_cache_on_config_change($cache_folder, $config_file, $packages_file);

// Load projects
$all_projects = [];
$mtime = 0;
if (!empty($confs['groups'])) {
    // We have to get projects from specifics groups
    foreach ($groups->all(['page' => 1, 'per_page' => 100]) as $group) {
        if (!in_array($group['name'], $confs['groups'], true)) {
            continue;
        }
        for ($page = 1; count($p = $groups->projects($group['id'], ['page' => $page, 'per_page' => 100])); $page++) {
            foreach ($p as $project) {
                $all_projects[] = $project;
                $mtime = max($mtime, strtotime($project['last_activity_at']));
            }
        }
    }
} else {
    // We have to get all accessible projects
    $me = $client->api('users')->me();
    for ($page = 1; count($p = $projects->all(['page' => $page, 'per_page' => 100])); $page++) {
        foreach ($p as $project) {
            // last_activity_at is not accurate https://gitlab.com/gitlab-org/gitlab/issues/20952
            $commit = $repos->commits($project['id'], ['per_page' => 1])[0]; // get last commit
            $project['last_activity_at'] = $commit['created_at']; // overwrite last_activity_at

            $all_projects[] = $project;
            $mtime = max($mtime, strtotime($project['last_activity_at']));
        }
    }
}
// Regenerate packages_file is needed
if (!file_exists($packages_file) || filemtime($packages_file) < $mtime || isset($_GET['force'])) {
    $packages = [];
    foreach ($all_projects as $project) {
        if (($package = load_data($project, $repos)) && ($package_name = get_package_name($project, $repos))) {
            $packages[$package_name] = $package;
        }
    }
    if (file_exists($static_file)) {
        $static_packages = json_decode(file_get_contents($static_file));
        foreach ($static_packages as $name => $package) {
            foreach ($package as $version => $root) {
                if (isset($root->extra)) {
                    $source = '_source';
                    while (isset($root->extra->{$source})) {
                        $source = '_' . $source;
                    }
                    $root->extra->{$source} = 'static';
                } else {
                    $root->extra = [
                        '_source' => 'static',
                    ];
                }
            }
            $packages[$name] = $package;
        }
    }
    $data = json_encode([
        'packages' => array_filter($packages),
    ]);

    file_put_contents($packages_file, $data);
    @chmod(0777, $packages_file);
}

outputFile($packages_file);
