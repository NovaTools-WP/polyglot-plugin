<?php

$rows = [];
for ($i = 0; $i < 10000; $i++) {
    $rows[] = ['code' => "code_$i", 'locale' => "locale_$i"];
}

// Benchmark foreach
$start = microtime(true);
for ($j = 0; $j < 1000; $j++) {
    $map = array();
    if ( is_array( $rows ) ) {
        foreach ( $rows as $row ) {
            $map[ $row['code'] ] = $row['locale'];
        }
    }
}
$timeForeach = microtime(true) - $start;

// Benchmark array_column
$start = microtime(true);
for ($j = 0; $j < 1000; $j++) {
    if ( is_array( $rows ) ) {
        $map = array_column( $rows, 'locale', 'code' );
    } else {
        $map = array();
    }
}
$timeArrayColumn = microtime(true) - $start;

echo "Foreach: {$timeForeach}s\n";
echo "Array Column: {$timeArrayColumn}s\n";
$improvement = ($timeForeach - $timeArrayColumn) / $timeForeach * 100;
echo "Improvement: " . round($improvement, 2) . "%\n";
