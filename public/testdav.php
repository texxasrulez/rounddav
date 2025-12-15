<?php
header('Content-Type: text/plain');
echo "METHOD=" . $_SERVER['REQUEST_METHOD'] . "\n";
echo "URI=" . $_SERVER['REQUEST_URI'] . "\n";
