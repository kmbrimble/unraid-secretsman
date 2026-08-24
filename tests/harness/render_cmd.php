<?php
declare(strict_types=1);

/**
 * secretsman staging harness — renders ONE template through ONE variant of
 * Helpers.php (stock or patched) and prints the resulting docker command to
 * stdout. Nothing else goes to stdout, ever — this is deliberately narrow
 * so a caller can diff two runs byte-for-byte.
 *
 * Meant to run ON the live Unraid host, read-only:
 *   php render_cmd.php <path-to-Helpers.php> <path-to-template.xml>
 *
 * Bootstraps only what xmlToCommand() actually needs — plugins/dynamix/
 * include/Wrappers.php (for _var() and friends) plus the Helpers.php
 * variant under test — rather than the full webGui HTTP-request bootstrap
 * chain (DockerClient.php pulls in Translations.php unconditionally in a
 * non-'docker' request context, which is unnecessary here and a needless
 * source of side effects for a CLI harness). The docker network queries
 * DockerUtil::driver()/custom()/network() perform are replicated inline
 * below with the exact same `docker network ls`/`docker network inspect`
 * commands, since those are the only pieces of that chain xmlToCommand()
 * actually needs ($driver, $subnet).
 *
 * $create_paths is always false: no mkdir/chown side effects on the host,
 * ever, from this script.
 */

if ($argc !== 3) {
    fwrite(STDERR, "usage: php render_cmd.php <helpers.php> <template.xml>\n");
    exit(2);
}
[, $helpersPath, $templatePath] = $argv;

$docroot = '/usr/local/emhttp';
chdir($docroot);

try {
    if (!is_file($helpersPath)) {
        throw new \RuntimeException("helpers file not found: {$helpersPath}");
    }
    if (!is_file($templatePath)) {
        throw new \RuntimeException("template not found: {$templatePath}");
    }

    require_once "$docroot/plugins/dynamix/include/Wrappers.php";
    require_once $helpersPath;

    $var = parse_ini_file('state/var.ini');
    if ($var === false) {
        throw new \RuntimeException('could not read state/var.ini');
    }

    exec("docker network ls --format='{{.Name}}={{.Driver}}'", $networkLines);
    $driver = [];
    foreach ($networkLines as $line) {
        [$net, $drv] = array_pad(explode('=', $line), 2, '');
        $driver[$net] = $drv;
    }

    exec(
        "docker network ls --filter driver='bridge' --filter driver='macvlan' --filter driver='ipvlan' " .
        "--format='{{.Name}}' 2>/dev/null | grep -v '^bridge$'",
        $customNetworks
    );
    $subnet = ['bridge' => '', 'host' => '', 'none' => ''];
    foreach ($customNetworks as $net) {
        exec(
            "docker network inspect --format='{{range .IPAM.Config}}{{println .Subnet}}{{end}}' " . escapeshellarg($net),
            $subnetLines
        );
        $subnet[$net] = implode(', ', array_filter($subnetLines));
        $subnetLines = [];
    }

    [$cmd, $name, $repo] = xmlToCommand($templatePath, false);
    echo $cmd, "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
