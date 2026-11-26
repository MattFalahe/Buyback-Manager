<?php

/*
 * Merged into SeAT's own list of SSO scopes (eveapi.scopes), so an admin can
 * tick it in a scope profile under Settings > SSO. Nothing adds it to a
 * profile for them: a scope only reaches a character's token when that
 * character next logs in with a profile that asks for it.
 *
 * esi-ui.open_window.v1 lets "Open in game" ask the EVE client to show a
 * contract.
 */

return [
    'esi-ui.open_window.v1',
];
