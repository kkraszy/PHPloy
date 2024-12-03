<?php

$pharFile = 'phploy.phar';

// Usuń poprzedni plik PHAR, jeśli istnieje
if (file_exists($pharFile)) {
    unlink($pharFile);
}

// Tworzenie obiektu PHAR
$phar = new Phar($pharFile, 0, 'phploy.phar');

// Dodanie plików do archiwum
$phar->buildFromDirectory(__DIR__, '/\.(php|html|css|js|json|yml|yaml)$/');

// Określenie pliku startowego
$phar->setStub($phar->createDefaultStub('index.php'));

// Opcjonalnie: Kompresja plików w archiwum
if (Phar::canCompress(Phar::GZ)) {
    $phar->compressFiles(Phar::GZ);
}

echo "Plik PHAR został wygenerowany: $pharFile\n";
