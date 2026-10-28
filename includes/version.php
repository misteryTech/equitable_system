<?php
/**
 * Version of this copy of the Equitable System, shown in the footer of every page and
 * on the login page, so you can see whether a computer or server runs the latest code.
 *
 * Before each push to GitHub: raise APP_VERSION, set APP_RELEASED to that day and add
 * the changes to CHANGELOG.md.
 *   1.2.0 -> 1.3.0  new features
 *   1.2.0 -> 1.2.1  fixes only
 */
const APP_VERSION  = '1.2.0';
const APP_RELEASED = '2026-10-28';   // Y-m-d

/**
 * "v1.2.0 · Oct 28, 2026"
 */
function app_version_label(): string
{
    return 'v' . APP_VERSION . ' · ' . date('M j, Y', strtotime(APP_RELEASED));
}
