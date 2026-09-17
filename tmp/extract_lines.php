<?php
$lines = file('assets/js/app-main.js', FILE_IGNORE_NEW_LINES);
for ($i = 2699; $i < min(2830, count($lines)); $i++) {
    echo ($i + 1) . ': ' . $lines[$i] . "\n";
}
