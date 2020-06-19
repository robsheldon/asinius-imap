<?php

/*******************************************************************************
*                                                                              *
*   Asinius\Imap\URL                                                           *
*                                                                              *
*   Coordinates operations for imap URLs.                                      *
*                                                                              *
*   LICENSE                                                                    *
*                                                                              *
*   Copyright (c) 2020 Rob Sheldon <rob@rescue.dev>                            *
*                                                                              *
*   Permission is hereby granted, free of charge, to any person obtaining a    *
*   copy of this software and associated documentation files (the "Software"), *
*   to deal in the Software without restriction, including without limitation  *
*   the rights to use, copy, modify, merge, publish, distribute, sublicense,   *
*   and/or sell copies of the Software, and to permit persons to whom the      *
*   Software is furnished to do so, subject to the following conditions:       *
*                                                                              *
*   The above copyright notice and this permission notice shall be included    *
*   in all copies or substantial portions of the Software.                     *
*                                                                              *
*   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS    *
*   OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF                 *
*   MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.     *
*   IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY       *
*   CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,       *
*   TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE          *
*   SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.                     *
*                                                                              *
*   https://opensource.org/licenses/MIT                                        *
*                                                                              *
*******************************************************************************/

namespace Asinius\Imap;


/*******************************************************************************
*                                                                              *
*   \Asinius\Imap\URL                                                          *
*                                                                              *
*******************************************************************************/

class URL
{

    protected $url = false;


    /**
     * Accept a URL object or string, and return a Datastream-compatible imap
     * connection object for that URL.
     *
     * @param   mixed       $url
     *
     * @throws  \RuntimeException
     *
     * @return  \Asinius\Imap\Connection
     */
    public static function open ($url)
    {
        if ( is_string($url) ) {
            $url = new \Asinius\URL($url);
        }
        if ( ! is_a($url, '\Asinius\URL') ) {
            throw new \RuntimeException("Can't open this kind of url: $url");
        }
        if ( empty($url->username) ) {
            throw new \RuntimeException("imap URLs need to include a username");
        }
        if ( empty($url->hostname) ) {
            throw new \RuntimeException("This does not look like a valid imap URL: \"$url\"");
        }
        if ( empty($url->password) ) {
            throw new \RuntimeException("imap URLs need to include a password");
        }
        $connection = new \Asinius\Imap\Connection($url->hostname, $url->username, $url->password);
        $connection->open();
        return $connection;
    }

}