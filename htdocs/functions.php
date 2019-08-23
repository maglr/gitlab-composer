<?php

use Gitlab\Exception\RuntimeException;

/**
 * @param $file
 * Output a json file, sending max-age header, then dies
 */
function outputFile($file)
{
    $mtime = filemtime($file);

    header('Content-Type: application/json');
    header('Last-Modified: ' . gmdate('r', $mtime));
    header('Cache-Control: max-age=0');

    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && ($since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) && $since >= $mtime) {
        header('HTTP/1.0 304 Not Modified');
    } else {
        readfile($file);
    }
    die();
}

/**
 * Retrieves some information about a project's composer.json
 *
 * @param array $project
 * @param string $ref commit id
 * @param Gitlab\Api\Repositories $repos
 * @return array|false
 */
function fetch_composer($project, $ref, $repos)
{
    try {
        $c = $repos->getFile($project['id'], 'composer.json', $ref);

        if (!isset($c['content'])) {
            return false;
        }

        $composer = json_decode(base64_decode($c['content']), true);

        if ((!empty($composer['type']) && $composer['type'] == 'project') && HiDE_PROJECTS) {
            return false; // hide all projects
        }

        if (empty($composer['name']) || (!ALLOW_PACKAGE_NAME_MISMATCH && strcasecmp($composer['name'], $project['path_with_namespace']) !== 0)) {
            return false; // packages must have a name and must match
        }

        return $composer;
    } catch (RuntimeException $e) {
        return false;
    }
}

/**
 * Retrieves some information about a project for a specific ref
 *
 * @param array $project
 * @param array $ref
 * @param Gitlab\Api\Repositories $repos
 * @return array   [$version => ['name' => $name, 'version' => $version, 'source' => [...]]]
 */
function fetch_ref($project, $ref, $repos)
{
    static $ref_cache = [];

    $ref_key = md5(serialize($project) . serialize($ref));

    if (!isset($ref_cache[$ref_key])) {
        if (preg_match('/^v?\d+\.\d+(\.\d+)*(\-(dev|patch|alpha|beta|RC)\d*)?$/', $ref['name'])) {
            $version = $ref['name'];
        } else {
            $version = 'dev-' . $ref['name'];
        }

        if (($data = fetch_composer($project, $ref['commit']['id'], $repos)) !== false) {
            $data['version'] = $version;
            $url = $project[METHOD . '_url_to_repo'];
            if (METHOD == 'ssh' && PORT != '') {
                $url = 'ssh://'.strstr($project['ssh_url_to_repo'], ':', true);
                $url .= ':'.PORT.'/'.$project['path_with_namespace'];
            }
            $data['source'] = [
                'url'       => $url,
                'type'      => 'git',
                'reference' => $ref['commit']['id'],
            ];

            $ref_cache[$ref_key] = [$version => $data];
        } else {
            $ref_cache[$ref_key] = [];
        }
    }

    return $ref_cache[$ref_key];
}


/**
 * Retrieves some information about a project for all refs
 * @param array $project
 * @param Gitlab\Api\Repositories $repos
 * @return array   Same as $fetch_ref, but for all refs
 */
function fetch_refs($project, $repos)
{
    $return = [];
    try {
        foreach (array_merge($repos->branches($project['id']), $repos->tags($project['id'])) as $ref) {
            foreach (fetch_ref($project, $ref, $repos) as $version => $data) {
                // duplicate branches that look like version numbers, so we can use both "dev-2.0 and 2.0"
                if (substr($version, 0, 4) !== 'dev-') {
                    $return['dev-'.$version] = $data;
                    $return['dev-'.$version]['version'] = 'dev-'.$version;
                }
                $return[$version] = $data;
            }
        }
    } catch (RuntimeException $e) {
        // The repo has no commits — skipping it.
    }

    return $return;
};

/**
 * Caching layer on top of $fetch_refs
 * Uses last_activity_at from the $project array, so no invalidation is needed
 *
 * @param array $project
 * @param Gitlab\Api\Repositories $repos
 * @return array Same as $fetch_refs
 */
function load_data($project, $repos)
{
    $file    = __DIR__ . "/../cache/{$project['path_with_namespace']}.json";
    $mtime   = strtotime($project['last_activity_at']);

    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }

    if (file_exists($file) && filemtime($file) >= $mtime) {
        if (filesize($file) > 0) {
            return json_decode(file_get_contents($file));
        } else {
            return false;
        }
    } elseif ($data = fetch_refs($project, $repos)) {
        file_put_contents($file, json_encode($data));
        touch($file, $mtime);
        @chmod(0777, $file);

        return $data;
    } else {
        $f = fopen($file, 'w');
        fclose($f);
        touch($file, $mtime);
        @chmod(0777, $file);

        return false;
    }
}

/**
 * Determine the name to use for the package.
 *
 * @param array $project
 * @param Gitlab\Api\Repositories $repos
 * @return string The name of the project
 */
function get_package_name($project, $repos)
{
    if (ALLOW_PACKAGE_NAME_MISMATCH) {
        $ref = fetch_ref($project, $repos->branch($project['id'], $project['default_branch']), $repos);
        return reset($ref)['name'];
    }

    return $project['path_with_namespace'];
}

/**
 * Clear the cache folder if the config.ini is newer than the packages.json file
 * @param string $cache_folder
 * @param string $config_file
 * @param string $packages_file
 * @return bool
 */
function clear_cache_on_config_change($cache_folder, $config_file, $packages_file)
{
    if (!is_dir($cache_folder)) {
        die('cache folder: '.$cache_folder.' does not exist');
    }
    if (!is_writable($cache_folder)) {
        die('cache folder: '.$cache_folder.' is not writable');
    }
    if (!file_exists($packages_file)) {
        return false;
    }
    if (filemtime($config_file) < filemtime($packages_file)) {
        return false;
    }

    if (!is_dir($cache_folder) || strlen($cache_folder) < 20) {
        die('clear_cache_on_config_change safety check failed');
    }
    shell_exec('rm -rfv '.$cache_folder.'/*');
    return true;
}
