<?php

namespace LeKoala\EmailTemplates\Tasks;

use SilverStripe\ORM\DB;
use SilverStripe\Dev\BuildTask;
use LeKoala\EmailTemplates\Models\SentEmail;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
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
        return Director::is_cli() && parent::isEnabled() && function_exists('gzcompress') && Config::forClass(SentEmail::class)->get('gzip_body');
    }

    public function run($request)
    {
        $table = SentEmail::singleton()->baseTable();
        $nonCompressed = DB::query("SELECT ID, \"Body\" FROM \"$table\" WHERE \"Compressed\" = 0 LIMIT 10000");

        $total = DB::query("SELECT COUNT(ID) FROM \"$table\" WHERE \"Compressed\" = 0")->value();

        if (!$nonCompressed) {
            echo "No non-compressed emails found\n";
            return;
        }
        foreach ($nonCompressed as $pos => $row) {
            $compressed = gzcompress($row->Body ?? '');
            SQLUpdate::create($table, [
                'Body' => $compressed,
                'Compressed' => 1,
            ], [
                'ID' => $row['ID'],
            ])->execute();
            $this->progress($pos, $total);
        }
        echo "\n";
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
            $percentInt = round($percent);
            $spinner = self::PROGRRESS_SPINNER[$pos % count(self::PROGRRESS_SPINNER)];
            $spinner = "\rConverting: $spinner";
            $spinner .= str_repeat(' ', 50 - strlen($spinner));
            $spinner .= " $percent%";
            echo $spinner;
            if ($percentInt > 0 && $percentInt % 100 == 0) {
                echo "\n";
            }
        }
    }
}
