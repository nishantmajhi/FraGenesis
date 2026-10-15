<?php
    function isPcOS(): bool {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];

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
