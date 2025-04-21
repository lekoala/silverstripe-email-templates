<?php

namespace LeKoala\EmailTemplates\Tasks;

use SilverStripe\ORM\DB;
use SilverStripe\Dev\BuildTask;
use LeKoala\EmailTemplates\Models\SentEmail;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\Connect\MySQLDatabase;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 *
 * Finds all non-compressed sent email bodies and compresses them
 *
 * Requires the gzip extension
 *
 * @author gurucomkz
 */
class CompressEmailBodiesTask extends BuildTask
{
    private static $segment = 'CompressEmailBodiesTask';

    protected $title = "Compress Email Bodies task";
    protected $description = "Finds all non-compressed sent email bodies and compresses them";

    public function isEnabled()
    {
        return Director::is_cli() && parent::isEnabled() && function_exists('gzdeflate') && Config::forClass(SentEmail::class)->get('compress_body');
    }

    public function run($request)
    {
        $table = SentEmail::singleton()->baseTable();
        $total = DB::query("SELECT COUNT(ID) FROM \"$table\" WHERE \"Compressed\" = 0")->value();

        if (!$total) {
            echo "No non-compressed emails found\n";
            return;
        }

        $nonCompressed = DB::query("SELECT ID, \"Body\" FROM \"$table\" WHERE \"Compressed\" = 0");

        echo "Found " . $total . " non-compressed emails\n";

        foreach ($nonCompressed as $pos => $row) {
            if (!$row['Body']) {
                echo "Email with ID {$row['ID']} has no body\n";
                continue;
            }
            $compressed = gzdeflate($row['Body'] ?? '');
            if ($compressed === false) {
                echo "\tFailed to compress email with ID {$row['ID']}\n";
                continue;
            }

            $base64compressed = SentEmail::COMPRESSED_SIGNATURE . base64_encode($compressed);

            SQLUpdate::create($table, [
                'Body' => $base64compressed,
                'Compressed' => 1,
            ], [
                'ID' => $row['ID'],
            ])->execute();

            $this->progress($pos, $total);
        }

        // optimise table
        $this->optimizeTable();
        echo "\n";
    }

    public function optimizeTable()
    {
        $table = SentEmail::singleton()->baseTable();
        echo "\nOptimizing $table table...\n";
        $db = DB::get_conn();
        if ($db instanceof MySQLDatabase) {
            DB::query("OPTIMIZE TABLE \"$table\"");
        } elseif (get_class($db) == "SilverStripe\PostgreSQL\PostgreSQLDatabase") {
            DB::query("VACUUM FULL \"$table\"");
        } else {
            echo "Database not supported for optimization\n";
        }
    }

    const PROGRRESS_SPINNER = [
        '⠋',
        '⠙',
        '⠹',
        '⠼',
        '⠴',
        '⠦',
        '⠧',
        '⠇',
    ];

    public function progress($pos, $total)
    {
        if ($total) {
            $percent = round($pos / $total * 100, 2);
            $percentInt = floor($percent);

            $spinner = "\rConverting: [";
            $spinner .= str_repeat('￭', floor($percentInt / 5));
            $edge = self::PROGRRESS_SPINNER[$pos % count(self::PROGRRESS_SPINNER)];
            $spinner .= "$edge";
            $spinner .= str_repeat(' ', 40 - strlen($spinner));
            $spinner .= "] $percent%   ";
            echo $spinner;
            if ($percentInt > 0 && $percentInt % 100 == 0) {
                echo "\n";
            }
        }
    }
}
