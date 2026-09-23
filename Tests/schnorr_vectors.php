<?php
// Runs the BIP-340 official test vectors against Modules\Nostr\Crypto\Schnorr.
// Usage: php Tests/schnorr_vectors.php /path/to/test-vectors.csv
require __DIR__.'/../vendor/autoload.php';

use Modules\Nostr\Crypto\Schnorr;

$file = $argv[1] ?? __DIR__.'/vectors/bip340.csv';
$rows = array_map('str_getcsv', file($file));
array_shift($rows);
$fail = 0; $pass = 0; $t0 = microtime(true);
foreach ($rows as $row) {
    [$index, $sk, $pk, $aux, $msg, $sig, $expected] = $row;
    $expected = strtoupper($expected) === 'TRUE';
    if ($sk !== '') {
        $pub = Schnorr::pubkey($sk);
        if (strtolower($pub) !== strtolower($pk)) { echo "#$index pubkey mismatch\n"; $fail++; continue; }
        $mySig = Schnorr::sign($msg, $sk, $aux);
        if (strtolower($mySig) !== strtolower($sig)) { echo "#$index signature mismatch\n"; $fail++; continue; }
    }
    $ok = Schnorr::verify($msg, $pk, $sig);
    if ($ok !== $expected) { echo "#$index verify expected ".var_export($expected, true)." got ".var_export($ok, true)."\n"; $fail++; continue; }
    $pass++;
}
printf("BIP-340 vectors: %d passed, %d failed (%.1fs, %s)\n", $pass, $fail, microtime(true) - $t0, extension_loaded('gmp') ? 'gmp' : 'bcmath');
exit($fail ? 1 : 0);
