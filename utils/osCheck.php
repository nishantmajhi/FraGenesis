<?php
    /**
     * Whether the request came from something with a keyboard and a printer.
     *
     * The site generates a question paper for printing, so the home page sends
     * phones and tablets to pages/premium.html instead.
     */
    function isPcOS(): bool {
        // A missing User-Agent is normal, not exceptional: curl -H 'User-Agent:',
        // a health check, a link preview. The original indexed $_SERVER without
        // checking, so those requests printed "Warning: Undefined array key
        // HTTP_USER_AGENT" and a stripos() null deprecation above the page.
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $pcOSList = [
            'Windows NT',
            'Macintosh',
            'Mac OS X',
            'macOS',
            'X11',
            'Linux x86_64',
            'Ubuntu',
            'Fedora',
            'Debian',
            'FreeBSD',
            'OpenBSD',
            'NetBSD',
            'Chrome OS'
        ];

        foreach ($pcOSList as $os) {
            if (stripos($userAgent, $os) !== false) return true;
        }

        return false;
    }
