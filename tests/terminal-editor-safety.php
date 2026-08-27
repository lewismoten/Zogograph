<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/terminal.php';

function editorExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

editorExpect(terminalEditorLineEnding("one\ntwo\n") === 'lf', 'LF files were not recognized');
editorExpect(terminalEditorLineEnding("one\r\ntwo\r\n") === 'crlf', 'CRLF files were not recognized');
editorExpect(terminalEditorLineEnding("one\rtwo\r") === 'cr', 'CR files were not recognized');
editorExpect(terminalEditorWithLineEndings("one\ntwo\n", 'crlf') === "one\r\ntwo\r\n",
    'editor did not restore CRLF after textarea normalization');
editorExpect(terminalEditorWithLineEndings("one\r\ntwo\r", 'lf') === "one\ntwo\n",
    'editor did not normalize old line endings consistently');
editorExpect(terminalEditorFingerprint('same') === terminalEditorFingerprint('same')
    && terminalEditorFingerprint('same') !== terminalEditorFingerprint('changed'),
    'editor fingerprint did not distinguish content');

try {
    terminalEditorWithLineEndings('x', 'unknown');
    throw new RuntimeException('invalid line ending was accepted');
} catch (ActionRefused) {
}

$root = sys_get_temp_dir() . '/hostscope-editor-root-' . bin2hex(random_bytes(4));
$outside = sys_get_temp_dir() . '/hostscope-editor-outside-' . bin2hex(random_bytes(4));
editorExpect(mkdir($root, 0700) && mkdir($outside, 0700), 'could not prepare browser test directories');
$root = realpath($root) ?: throw new RuntimeException('could not resolve browser test root');
mkdir($root . '/child', 0700);
file_put_contents($root . '/note.txt', 'safe');
@symlink($outside, $root . '/outside-link');
editorExpect(terminalEditorBrowseDirectory($root, 'child') === $root . '/child',
    'browser did not resolve a direct child directory');
try {
    terminalEditorBrowseDirectory($root, '../' . basename($outside));
    throw new RuntimeException('browser accepted a directory outside the watched root');
} catch (ActionRefused) {
}
try {
    terminalEditorBrowseDirectory($root, 'outside-link');
    throw new RuntimeException('browser followed a link outside the watched root');
} catch (ActionRefused) {
}
@unlink($root . '/outside-link');
@unlink($root . '/note.txt');
@rmdir($root . '/child');
@rmdir($root);
@rmdir($outside);

$directory = sys_get_temp_dir() . '/hostscope-editor-test-' . bin2hex(random_bytes(4));
$config = ['database' => $directory . '/stats.db'];
$copy = terminalEditorBackup($config, '/watched/one.txt', 'before');
editorExpect($copy !== null && count(terminalEditorBackups($config, '/watched/one.txt')) === 1,
    'editor recovery copy was not discoverable for its source file');
editorExpect(terminalEditorBackups($config, '/watched/two.txt') === [],
    'editor recovery copy appeared for a different file');
@unlink($directory . '/terminal/editor-backups/' . $copy);
@rmdir($directory . '/terminal/editor-backups');
@rmdir($directory . '/terminal');
@rmdir($directory);

echo "terminal editor safety checks passed\n";
