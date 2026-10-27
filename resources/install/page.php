<?php
/**
 * The install page (see App\Install\WebInstaller). Plain PHP: Laravel isn't
 * running yet. $e() escapes; $checks, $ready, $form, $problem, $url, $drivers.
 */
$needed = array_values(array_filter($checks, fn ($c) => $c['needed']));
$extra = array_values(array_filter($checks, fn ($c) => ! $c['needed']));
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Install · Booklet</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="<?= $e($url) ?>/booklet/admin.css">
    <style>
        .install-checks { list-style: none; margin: 22px 0 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
        .install-checks li { display: grid; grid-template-columns: 26px 1fr; gap: 2px 10px; padding: 9px 10px; border-radius: 10px; }
        .install-checks li.is-bad { background: color-mix(in srgb, var(--coral) 14%, var(--paper)); }
        .install-checks .mark { grid-row: span 2; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; border-radius: 7px; font-size: 13px; font-weight: 600; background: var(--mint); color: #1E1C18; }
        .install-checks .is-bad .mark { background: var(--danger); color: var(--paper); }
        .install-checks .is-maybe .mark { background: var(--edge-1); color: var(--muted); }
        .install-checks strong { font-weight: 500; font-size: 14.5px; }
        .install-checks small { font-size: 12.5px; line-height: 1.45; color: var(--muted); }
        .install-extra { margin-top: 18px; }
        .install-extra summary { cursor: pointer; font-size: 13px; color: var(--muted); min-height: 36px; display: flex; align-items: center; }
        .install-form:has(input[value="sqlite"]:checked) .install-mysql { display: none; }
        .install-mysql { display: flex; flex-direction: column; gap: 14px; }
        .install-row { display: grid; grid-template-columns: 1fr 110px; gap: 12px; }
        .install-problem { padding: 12px 14px; border-radius: 12px; background: color-mix(in srgb, var(--coral) 16%, var(--paper)); font-size: 14.5px; line-height: 1.5; }
        @media (max-width: 520px) { .install-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="desk desk--center">
<main class="setup">
    <div class="book setup__book">
        <div class="book__pages">
            <section class="page page--left" style="flex-basis: 380px">
                <div style="display: flex; align-items: center; gap: 10px">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 5c3-1.5 6.5-1.5 10 1 3.5-2.5 7-2.5 10-1v14c-3-1.5-6.5-1.5-10 1-3.5-2.5-7-2.5-10-1z"/><path d="M12 6v14"/></svg>
                    <span class="serif" style="font-size: 22px; font-weight: 500">booklet</span>
                </div>
                <h1 class="display" style="margin-top: 36px">Install Booklet</h1>
                <p class="lede" style="margin-top: 12px">First, a look at this server.<?= $ready ? ' It has everything Booklet needs.' : ' A few things need fixing before Booklet can be installed.' ?></p>

                <ul class="install-checks">
                    <?php foreach ($needed as $c): ?>
                        <li class="<?= $c['ok'] ? '' : 'is-bad' ?>">
                            <span class="mark" aria-hidden="true"><?= $c['ok'] ? '✓' : '!' ?></span>
                            <strong><?= $e($c['label']) ?><span class="sr-only"><?= $c['ok'] ? ': fine' : ': needs fixing' ?></span></strong>
                            <small><?= $e($c['text']) ?></small>
                        </li>
                    <?php endforeach ?>
                </ul>

                <details class="install-extra">
                    <summary>Nice to have (<?= count(array_filter($extra, fn ($c) => $c['ok'])) ?> of <?= count($extra) ?>)</summary>
                    <ul class="install-checks" style="margin-top: 6px">
                        <?php foreach ($extra as $c): ?>
                            <li class="<?= $c['ok'] ? '' : 'is-maybe' ?>">
                                <span class="mark" aria-hidden="true"><?= $c['ok'] ? '✓' : '–' ?></span>
                                <strong><?= $e($c['label']) ?></strong>
                                <small><?= $e($c['text']) ?></small>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </details>

                <p class="page__folio">Before the preface</p>
            </section>

            <section class="page page--right" style="--ribbon: var(--coral)">
                <div class="ribbon" aria-hidden="true"></div>
                <p class="eyebrow">Where Booklet keeps things</p>

                <form method="post" class="setup__form install-form" novalidate onsubmit="var b=this.querySelector('[type=submit]'); b.disabled=true; b.textContent='Installing…'">
                    <input type="hidden" name="action" value="install">
                    <h2 class="heading">Choose a database</h2>

                    <?php if ($problem): ?>
                        <p class="install-problem" role="alert"><?= $e($problem) ?></p>
                    <?php endif ?>

                    <fieldset class="engine-pick" style="border: 0; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px">
                        <legend class="sr-only">Database</legend>
                        <label class="engine-option<?= in_array('sqlite', $drivers, true) ? '' : ' is-off' ?>">
                            <input type="radio" name="driver" value="sqlite" <?= $form['driver'] === 'sqlite' ? 'checked' : '' ?> <?= in_array('sqlite', $drivers, true) ? '' : 'disabled' ?>>
                            <span><strong>A file, kept with Booklet (simplest)</strong><small>Nothing to set up. Fine for most sites; it’s included in Booklet’s backups.</small></span>
                        </label>
                        <label class="engine-option<?= in_array('mysql', $drivers, true) ? '' : ' is-off' ?>">
                            <input type="radio" name="driver" value="mysql" <?= $form['driver'] === 'mysql' ? 'checked' : '' ?> <?= in_array('mysql', $drivers, true) ? '' : 'disabled' ?>>
                            <span><strong>MySQL or MariaDB</strong><small>The database your hosting panel makes for you (under “MySQL databases”).</small></span>
                        </label>
                    </fieldset>

                    <div class="install-mysql">
                        <div class="install-row">
                            <div class="field">
                                <label for="host">Server</label>
                                <input id="host" name="host" type="text" value="<?= $e($form['host']) ?>" autocomplete="off" spellcheck="false">
                            </div>
                            <div class="field">
                                <label for="port">Port</label>
                                <input id="port" name="port" type="text" inputmode="numeric" value="<?= $e($form['port']) ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label for="database">Database name</label>
                            <input id="database" name="database" type="text" value="<?= $e($form['database']) ?>" autocomplete="off" spellcheck="false">
                            <p class="field__hint">If it isn’t there yet, Booklet tries to make it.</p>
                        </div>
                        <div class="field">
                            <label for="username">Username</label>
                            <input id="username" name="username" type="text" value="<?= $e($form['username']) ?>" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" value="" autocomplete="new-password">
                        </div>
                    </div>

                    <p class="field__hint" style="margin: 0">Booklet will live at <strong style="font-weight: 500; color: var(--ink)"><?= $e($url) ?></strong>. Next: your site’s name and your sign-in.</p>

                    <div class="setup__nav">
                        <span></span>
                        <button type="submit" class="btn btn--ink" <?= $ready ? '' : 'disabled' ?>>Install →</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</main>
</body>
</html>
