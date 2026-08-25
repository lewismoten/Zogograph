<?php
declare(strict_types=1);

/*
 * The assistant's own database, deliberately apart from the other two.
 *
 * Three reasons it is its own file rather than more tables in the account
 * database.
 *
 * It is a transcript of conversations with an outside service, including
 * whatever the model was told about this machine. That is a different kind of
 * thing from a disk reading, it has different retention needs, and somebody who
 * wants it gone should be able to delete one file rather than pick tables apart.
 *
 * It is written on a completely different rhythm - a burst of rows every few
 * seconds while somebody is chatting, nothing for days afterwards - and mixing
 * that with the collector's steady append pattern makes both slower.
 *
 * And the polling that drives the whole conversation reads this file several
 * times a second. Keeping that traffic off the database the collectors are
 * writing to is worth a file on its own.
 *
 * Nothing here is required for the rest of the dashboard to work. If this file
 * cannot be created, the assistant is unavailable and everything else carries
 * on, which is the correct failure for a feature that is an extra.
 */

require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/spans.php';

/** Where it lives: beside the other two, which .htaccess already denies. */
function aiDatabasePath(array $config): string
{
    $configured = (string)($config['ai']['database'] ?? '');
    if ($configured !== '') {
        return $configured;
    }
    return dirname((string)$config['database']) . '/ai.db';
}

/**
 * Opens it, creating and migrating if needed.
 *
 * Returns null rather than throwing when it cannot be opened: the assistant is
 * an extra, and a dashboard that refused to load because a chat log could not
 * be written would be a poor trade.
 */
function aiDb(array $config, bool $forWriting = true): ?PDO
{
    static $handle = null;
    if ($handle !== null) {
        return $handle;
    }

    $path = aiDatabasePath($config);

    if (!$forWriting && !is_file($path)) {
        return null;
    }

    try {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
            return null;
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        /*
         * A short busy timeout, because this is polled hard.
         *
         * Several requests a second read this file while a conversation is
         * running. Without a timeout a reader that meets a writer fails
         * immediately, and the chat window shows an error for something that
         * would have succeeded a millisecond later.
         */
        $pdo->exec('PRAGMA busy_timeout = 3000');

        aiMigrate($pdo);
        @chmod($path, 0600);

        $handle = $pdo;
        return $handle;
    } catch (Throwable) {
        return null;
    }
}

function aiMigrate(PDO $pdo): void
{
    $at = (int)$pdo->query('PRAGMA user_version')->fetchColumn();

    foreach (aiMigrations() as $version => $statements) {
        if ($version <= $at) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
            $pdo->exec('PRAGMA user_version = ' . (int)$version);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

function aiMigrations(): array
{
    return [
        1 => [
            /*
             * One conversation.
             *
             * A session outlives the chat window: closing the panel is not the
             * same as ending the conversation, because somebody following a
             * suggestion to look at another page has not finished talking - and
             * making them start again would lose everything the model had
             * worked out.
             *
             * `spent_cents` is a running total in hundredths of a cent, kept as
             * an integer. Money in floating point accumulates error, and a
             * budget that is enforced against a number drifting by a fraction
             * each turn is a budget that stops working.
             */
            "CREATE TABLE ai_sessions (
                id            INTEGER PRIMARY KEY,
                started_at    INTEGER NOT NULL,
                last_at       INTEGER NOT NULL,
                ended_at      INTEGER,
                title         TEXT,
                state         TEXT NOT NULL,
                model         TEXT NOT NULL,
                stop_reason   TEXT,
                started_by    TEXT,
                opened_from   TEXT,
                turns         INTEGER NOT NULL DEFAULT 0,
                tokens_in     INTEGER NOT NULL DEFAULT 0,
                tokens_out    INTEGER NOT NULL DEFAULT 0,
                tokens_cached INTEGER NOT NULL DEFAULT 0,
                spent_millicents INTEGER NOT NULL DEFAULT 0,
                max_seconds   INTEGER NOT NULL DEFAULT 0,
                max_millicents INTEGER NOT NULL DEFAULT 0,
                max_turns     INTEGER NOT NULL DEFAULT 0
            )",
            'CREATE INDEX ai_sessions_state ON ai_sessions (state, last_at)',

            /*
             * Every message, in the order it happened.
             *
             * Including the tool results, which are messages as far as the model
             * is concerned - and are the part somebody auditing this will care
             * about most, because they are what left the machine.
             */
            "CREATE TABLE ai_messages (
                id         INTEGER PRIMARY KEY,
                session_id INTEGER NOT NULL REFERENCES ai_sessions(id) ON DELETE CASCADE,
                at         INTEGER NOT NULL,
                role       TEXT NOT NULL,
                content    TEXT,
                tool_name  TEXT,
                tool_id    TEXT,
                tool_args  TEXT,
                visible    INTEGER NOT NULL DEFAULT 1
            )",
            'CREATE INDEX ai_messages_session ON ai_messages (session_id, id)',

            /*
             * One request to the model, with what it cost.
             *
             * The whole request and response are kept. They are the only record
             * of what was sent to somebody else's service about this machine,
             * and a summary would be exactly the wrong thing to have when that
             * question is being asked.
             */
            "CREATE TABLE ai_calls (
                id         INTEGER PRIMARY KEY,
                session_id INTEGER NOT NULL REFERENCES ai_sessions(id) ON DELETE CASCADE,
                at         INTEGER NOT NULL,
                ms         INTEGER NOT NULL DEFAULT 0,
                model      TEXT NOT NULL,
                tokens_in  INTEGER NOT NULL DEFAULT 0,
                tokens_out INTEGER NOT NULL DEFAULT 0,
                tokens_cached INTEGER NOT NULL DEFAULT 0,
                millicents INTEGER NOT NULL DEFAULT 0,
                request    TEXT,
                response   TEXT,
                error      TEXT,
                http_status INTEGER
            )",
            'CREATE INDEX ai_calls_session ON ai_calls (session_id, id)',

            /*
             * Every tool the model asked for, and what happened.
             *
             * `state` walks: proposed -> (allowed | denied) -> done | failed.
             * A tool needing permission stops at `proposed` until a person says
             * otherwise, and a tool the browser has to run stops at `allowed`
             * until the browser comes back with an answer.
             */
            "CREATE TABLE ai_tool_runs (
                id         INTEGER PRIMARY KEY,
                session_id INTEGER NOT NULL REFERENCES ai_sessions(id) ON DELETE CASCADE,
                call_id    INTEGER,
                at         INTEGER NOT NULL,
                settled_at INTEGER,
                tool       TEXT NOT NULL,
                tool_id    TEXT NOT NULL,
                arguments  TEXT NOT NULL,
                runs_where TEXT NOT NULL,
                state      TEXT NOT NULL,
                needs_permission INTEGER NOT NULL DEFAULT 0,
                decided_by TEXT,
                result     TEXT,
                error      TEXT,
                ms         INTEGER NOT NULL DEFAULT 0
            )",
            'CREATE INDEX ai_tool_runs_session ON ai_tool_runs (session_id, id)',
            'CREATE INDEX ai_tool_runs_state ON ai_tool_runs (state, session_id)',
        ],

        2 => [
            /*
             * Pictures made, and what they cost in budget.
             *
             * The hourly limit is counted from this table rather than held in
             * memory, so restarting PHP does not reset it - which on a shared
             * host happens constantly.
             *
             * The URL rather than the bytes: the image lives on the provider's
             * CDN and the browser fetches it directly, so this never holds a
             * megabyte of JPEG to pass along. The consequence is that these
             * expire when the provider expires them, which is fine for a
             * throwaway aside and is why the prompt is kept alongside.
             */
            "CREATE TABLE ai_images (
                id         INTEGER PRIMARY KEY,
                session_id INTEGER,
                at         INTEGER NOT NULL,
                kind       TEXT NOT NULL,
                prompt     TEXT NOT NULL,
                style      TEXT,
                url        TEXT,
                error      TEXT
            )",
            'CREATE INDEX ai_images_at ON ai_images (at)',
            'CREATE INDEX ai_images_kind ON ai_images (kind, id)',
        ],
    ];
}

/**
 * How large the assistant's database has grown.
 *
 * Reported on the settings page, because transcripts of conversations that
 * include screenshots are the one thing here that can grow quickly and quietly.
 */
function aiDatabaseFacts(array $config): array
{
    $path = aiDatabasePath($config);

    return [
        'path' => $path,
        'exists' => is_file($path),
        'bytes' => is_file($path) ? (int)filesize($path) : 0,
        'wal_bytes' => is_file($path . '-wal') ? (int)filesize($path . '-wal') : 0,
        'writable' => is_writable(is_file($path) ? $path : dirname($path)),
    ];
}
