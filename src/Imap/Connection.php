<?php

/*******************************************************************************
*                                                                              *
*   Asinius\Imap\Connection                                                    *
*                                                                              *
*   A little wrapper class for PHP's imap_* functions.                         *
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
*   Constants                                                                  *
*                                                                              *
*******************************************************************************/

//  Assorted flags for adjusting imap connection behavior.
//  Setting this flag with Connection::set_global() will cause it not to test
//  the network when establishing a connection. This could make connection
//  errors harder to troubleshoot.
defined('MUST_GO_FASTER')           or define('MUST_GO_FASTER', 1);



/*******************************************************************************
*                                                                              *
*   Shutdown function.                                                         *
*                                                                              *
*******************************************************************************/

//  Cleanup and clean connection shutdowns need to happen when the application
//  terminates. Your application should probably also do something like:
//      declare(ticks = 1);
//      pcntl_signal(SIGINT, ['\Asinius\Imap\Connection', 'close_all']);
//  ...if you'd like connections to properly clean up and close when you ctrl-C
//  your program.
register_shutdown_function(function(){
    \Asinius\Imap\Connection::close_all();
});



/*******************************************************************************
*                                                                              *
*   \Asinius\Imap\Connection                                                   *
*                                                                              *
*******************************************************************************/

class Connection
{

    protected static $_all_connections  = [];
    protected static $_globals          = 0;

    protected $_status                  = 0;
    protected $_imap_connection         = false;
    protected $_configuration           = '';
    protected $_current_mailbox         = [
        'path'  => '',
        'flags' => OP_READONLY,
    ];
    protected $_expunge_on_close        = false;

    use \Asinius\DatastreamProperties;


    /**
     * This gets called internally to keep track of the imap connections currently
     * in use.
     *
     * @internal
     *
     * @param   Connection  $connection
     *
     * @return void
     */
    protected static function add_instance ($connection)
    {
        $index = array_search($connection, static::$_all_connections, true);
        if ( $index === false ) {
            static::$_all_connections[] = $connection;
        }
    }


    /**
     * This gets called internally to remove a connection from the list of
     * active connections.
     *
     * @internal
     * 
     * @param   Connection  $connection
     * 
     * @return  void
     */
    protected static function close_instance ($connection)
    {
        $index = array_search($connection, static::$_all_connections, true);
        if ( $index !== false ) {
            static::$_all_connections[$index]->close();
            unset(static::$_all_connections[$index]);
        }
    }


    /**
     * An internal function that handles connection cleanup during an application
     * shutdown, especially if it's an unexpected exit. This gets called by
     * register_shutdown_function() so it needs to be public.
     *
     * @internal
     * 
     * @return  void
     */
    public static function close_all ()
    {
        foreach (static::$_all_connections as $instance) {
            $instance->close();
        }
        static::$_all_connections = [];
        //  Clear out any lingering imap errors and warnings so PHP doesn't spit them out.
        imap_errors();
        imap_alerts();
    }


    /**
     * Set a global flag that changes the behavior of all imap connections.
     * 
     * @param   int         $flag
     *
     * @return  void
     */
    public static function set_global ($flag)
    {
        static::$_globals |= $flag;
    }


    /**
     * Internal function that juggles connections to different imap mailboxes.
     * In PHP's imap_* functions, each "folder" (mailbox) requires its own
     * connection. Connections can be cheaply switched once established, but
     * you can't ask for operations on a "Trash" folder while connected to the
     * "Inbox" folder.
     *
     * @param   string      $mailbox
     * @param   int         $flags
     *
     * @internal
     *
     * @throws  \RuntimeException
     *
     * @return  void
     */
    protected function _use_mailbox ($mailbox, $flags = OP_READONLY)
    {
        if ( $this->_status != 1 ) {
            return;
        }
        $flags &= ~OP_ANONYMOUS;
        $flags &= ~CL_EXPUNGE;
        if ( $mailbox != $this->_current_mailbox['path'] || ($flags != $this->_current_mailbox['flags'] && $this->_current_mailbox['flags'] !== 0 && $flags != OP_READONLY) ) {
            if ( $this->_expunge_on_close ) {
                imap_expunge($this->_imap_connection);
                $this->_expunge_on_close = false;
            }
            if ( ! imap_reopen($this->_imap_connection, $this->_configuration . $mailbox, $flags) ) {
                throw new \RuntimeException("Error switching to mailbox $mailbox");
            }
            $this->_current_mailbox['path'] = $mailbox;
            $this->_current_mailbox['flags'] = $flags;
        }
    }


    /**
     * Create a new imap connection object. This does not open the imap connection.
     *
     * @param   string      $hostname
     * @param   string      $username
     * @param   string      $password
     * @param   array       $options
     *
     * @return  Asinius\Imap\Connection
     */
    public function __construct ($hostname, $username, $password, $options = [])
    {
        $this->hostname = $hostname;
        $this->username = $username;
        $this->password = $password;
        $this->mailbox  = '';
        $this->port     = [993, 143];
        if ( ! empty($options) ) {
            foreach ($options as $key => $value) {
                switch ($key) {
                    case 'port':
                        $this->port = is_array($options['port']) ? $options['port'] : [$options['port']];
                        break;
                    case 'mailbox':
                        $this->mailbox = $options['mailbox'];
                        break;
                }
            }
        }
        Connection::add_instance($this);
    }


    /**
     * Ensure that the connection is properly closed when the object is destroyed.
     *
     * @internal
     *
     * @return  void
     */
    public function __destruct ()
    {
        $this->close();
    }


    /**
     * Open an imap connection to the server described in __construct().
     * NOTE: Unless Connection::set_global(MUST_GO_FASTER) is called, this
     * function will perform a quick(...ish) network test before establishing
     * the connection. This helps with troubleshooting; if the network test
     * indicates that there may be a problem, the function will throw a
     * \RuntimeException with a descriptive error message.
     *
     * A password can optionally be provided if this connection is being
     * re-open()ed after a previous close() or if the password wasn't provided
     * in the constructor.
     *
     * Mailboxes are opened read-only by default, and get reopened for writing
     * if the application deletes a message.
     *
     * @param   string      $password
     * 
     * @throws  \RuntimeException
     *
     * @return  void
     */
    public function open ($password = '')
    {
        //  open() tries a series of connection options from more secure to
        //  least-secure. If a port was specified, then only that port is tried.
        if ( ! static::$_globals & MUST_GO_FASTER ) {
            //  Test the network before continuing.
            $network = \Asinius\Network::test();
            if ( $network['status'] == 'offline' ) {
                throw new \RuntimeException("Can't connect to remote imap server; the network is currently offline");
            }
        }
        if ( $password == '' ) {
            $password = $this->password;
        }
        //  List of available connection configurations, ordered from most
        //  secure to least.
        $configurations = [
            ['imap', 'ssl', 'secure', 'validate-cert'],
            ['imap', 'ssl', 'secure', 'novalidate-cert'],
            ['imap', 'tls', 'secure'],
            ['imap', 'tls', 'secure', 'novalidate-cert'],
            ['imap'],
        ];
        foreach ($this->port as $port) {
            $disabled_options = [];
            foreach ($configurations as $configuration) {
                if ( $port == 143 && in_array('ssl', $configuration) ) {
                    //  Assume no SSL support on 143.
                    continue;
                }
                if ( ! empty(array_intersect($disabled_options, $configuration) ) ) {
                    //  If any of the available options for this port have been
                    //  disabled by an error from a previous attempt, skip it.
                    continue;
                }
                $this->_configuration = sprintf('{%s:%s/%s}', $this->hostname, $port, implode('/', $configuration));
                $saved = error_reporting(0);
                $this->_imap_connection = @imap_open($this->_configuration, $this->username, $password, OP_HALFOPEN, 1);
                error_reporting($saved);
                //  Errors and alerts should be cleared here before continuing.
                $errors = imap_errors();
                $alerts = imap_alerts();
                if ( $this->_imap_connection !== false ) {
                    //  Successful connection. Save the port value that worked.
                    $this->port = $port;
                    break 2;
                }
                else {
                    //  imap_errors() and imap_alerts() can each return false
                    //  if no errors are available, instead of an empty array.
                    $messages = array_merge($errors === false ? [] : $errors, $alerts === false ? [] : $alerts);
                    foreach ($messages as $message) {
                        switch (1) {
                            case (preg_match('/: Connection refused$/', $message)):
                                //  This port is no good, so skip it.
                                continue 4;
                            case (preg_match('/: SSL negotiation failed$/i', $message)):
                                //  Assume SSL doesn't work here?
                                $disabled_options[] = 'ssl';
                                continue 2;
                            case (preg_match('/^Unable to negotiate TLS with this server$/i', $message)):
                                //  Assume TLS doesn't work here.
                                $disabled_options[] = 'tls';
                                continue 2;
                            case (preg_match('/Authentication failed/', $message)):
                                //  A connection was negotiated but the username or password are wrong.
                                //  Stop trying; avoid tripping any security systems on the remote end.
                                //  TODO: An appropriate Datastream error needs to be stored here.
                                return;
                        }
                    }
                }
            }
        }
        //  Don't keep the password in memory past this point.
        $this->password = '';
        $this->_lock_property('hostname');
        $this->_lock_property('username');
        $this->_lock_property('password');
        $this->_lock_property('mailbox');
        $this->_lock_property('port');
        if ( $this->_imap_connection === false ) {
            $this->_configuration = '';
        }
        $this->_status = 1;
    }


    /**
     * Returns true if a connection is successfully established and not currently
     * stuck with an error.
     *
     * @return  boolean
     */
    public function ready ()
    {
        return $this->_status == 1 && $this->_imap_connection !== false;
    }


    /**
     * Close the current connection. This doesn't destroy the object; the same
     * connection can be reopened with open().
     *
     * @return  void
     */
    public function close ()
    {
        $this->_status = -1;
        if ( $this->_imap_connection !== false ) {
            if ( $this->_expunge_on_close ) {
                imap_expunge($this->_imap_connection);
                $this->_expunge_on_close = false;
            }
            imap_close($this->_imap_connection);
            $this->_imap_connection = false;
            $this->_configuration = '';
            $this->_current_mailbox = [
                'path'  => '',
                'flags' => OP_READONLY,
            ];
            Connection::close_instance($this);
        }
    }


    /**
     * Return a list of the folders in this account.
     * In this class, there is a difference between imap "mailboxes" and "folders".
     * Imap mailbox names use imap delimiters and may be utf7 encoded; folders are
     * what the user would see in their imap software. Mailboxes are used
     * internally, while application code will typically work with folders.
     *
     * @param   string      $search
     *
     * @throws  \RuntimeException
     *
     * @return  \Asinius\StrictArray
     */
    public function get_folders ($search = '%')
    {
        if ( $this->_status == -1 ) {
            return [];
        }
        if ( ! $this->ready() ) {
            throw new \RuntimeException("No imap connection");
        }
        $mailboxes = imap_getmailboxes($this->_imap_connection, $this->_configuration, $search);
        if ( ! is_array($mailboxes) ) {
            //  TODO: Better error/alert handling. Look up and parse the last
            //  relevant error or alert.
            throw new \RuntimeException("Error when retrieving mailboxes");
        }
        //  A StrictArray object is used here because imap mailbox names may be
        //  numeric, and PHP implicitly recasts numeric strings in array keys to
        //  integers and eventually they get all screwed up.
        $folders = new \Asinius\StrictArray();
        foreach ($mailboxes as $mailbox) {
            $folder_path = substr(imap_utf7_decode($mailbox->name), strlen($this->_configuration));
            $folder = new \Asinius\Imap\Folder($this, $folder_path, $mailbox->delimiter, $mailbox->attributes);
            //  Nested folders are now lazy-loaded and don't need to be traversed.
            $folders[$folder->name] = $folder;
        }
        if ( ! $this->_property_exists('folders') ) {
            $this->folders = $folders;
            $this->_lock_property('folders');
        }
        return $folders;
    }


    /**
     * Return the number of messages in the current mailbox (folder).
     *
     * @param   string      $mailbox
     *
     * @return  int
     */
    public function get_message_count ($mailbox = 'INBOX')
    {
        if ( $this->_status != 1 ) {
            return 0;
        }
        $this->_use_mailbox($mailbox);
        return imap_num_msg($this->_imap_connection);
    }


    /**
     * Return a list of message UIDs in the current mailbox (folder).
     *
     * @param   string      $mailbox
     *
     * @return  array
     */
    public function get_message_uids ($mailbox = 'INBOX')
    {
        if ( $this->_status != 1 ) {
            return [];
        }
        $this->_use_mailbox($mailbox);
        //$messages = imap_search($this->_imap_connection, 'ALL', SE_UID);
        $messages = imap_sort($this->_imap_connection, SORTARRIVAL, 1, SE_UID | SE_NOPREFETCH);
        return ($messages === false ? [] : $messages);
    }


    /**
     * Retrieve the headers for a specific email message in a specified mailbox.
     *
     * @param   string      $mailbox
     * @param   int         $message_uid
     *
     * @throws  \RuntimeException
     *
     * @return  string
     */
    public function retrieve_message_header ($mailbox, $message_uid)
    {
        if ( $this->_status != 1 ) {
            return '';
        }
        $this->_use_mailbox($mailbox);
        try {
            $header = @imap_fetchheader($this->_imap_connection, $message_uid, FT_UID);
        } catch (Exception $e) {
            ;
        }
        if ( empty($header) ) {
            $msg_index = @imap_msgno($this->_imap_connection, $message_uid);
            if ( $msg_index === false || $msg_index < 1 ) {
                throw new \RuntimeException("Lost the imap connection");
            }
        }
        return $header;
    }


    /**
     * Write a message to a mailbox (inbox by default).
     *
     * @param   string      $message
     * @param   string      $mailbox
     *
     * @return  boolean
     */
    public function put_message ($message, $mailbox = 'INBOX')
    {
        if ( $this->_status != 1 ) {
            return;
        }
        return imap_append($this->_imap_connection, $this->_configuration . $mailbox, $message);
    }


    /**
     * Delete a message from a mailbox.
     *
     * @param   string      $mailbox
     * @param   int         $message_uid
     *
     * @return  boolean
     */
    public function delete_message ($mailbox, $message_uid)
    {
        if ( $this->_status != 1 ) {
            return;
        }
        $this->_use_mailbox($mailbox, 0);
        $this->_expunge_on_close = true;
        return imap_delete($this->_imap_connection, $message_uid, FT_UID);
    }

}
