<?php

/*******************************************************************************
*                                                                              *
*   Asinius\Imap\Folder                                                        *
*                                                                              *
*   Encapsulates folders (mailboxes) in imap connections.                      *
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
*   \Asinius\Imap\Folder                                                       *
*                                                                              *
*******************************************************************************/

class Folder implements \Asinius\Datastream
{

    protected $_imap_connection = false;
    protected $_mailbox_path    = '';
    protected $_path_delimiter  = '';
    protected $_attributes      = 0;
    protected $_message_ids     = [];
    protected $_message_id_idx  = 0;

    use \Asinius\DatastreamProperties;


    /**
     * Instantiate a new folder.
     * 
     * @param   \Asinius\Imap\Connection    $connection
     * @param   string                      $path
     *
     * @return  \Asinius\Imap\Folder
     */
    public function __construct ($connection, $path = '', $delimiter = '', $attributes = 0)
    {
        \Asinius\Asinius::assert_parent(['\Asinius\Imap\Connection', '\Asinius\Imap\Folder']);
        $this->_imap_connection = $connection;
        $this->_mailbox_path = $path;
        $this->_path_delimiter = $delimiter;
        $this->_attributes = $attributes;
    }


    /**
     * Destroy this imap folder object. Not used.
     *
     * @return  void
     */
    public function __destruct ()
    {
        //  Nothing to do.
    }


    /**
     * Open the folder. Unused; this is done implicitly by the \Asinius\Imap\Connection
     * object. This is only here for compatibility with \Asinius\Datastream.
     *
     * @return  void
     */
    public function open ()
    {
    }


    /**
     * Returns true if the imap connection for this folder is active and ready.
     *
     * @return  boolean
     */
    public function ready ()
    {
        return strlen($this->_mailbox_path) > 0 && is_a($this->_imap_connection, '\Asinius\Imap\Connection') && $this->_imap_connection->ready();
    }


    /**
     * Return any captured errors for this folder. Currently unused. TODO.
     *
     * @return  void
     */
    public function errors ()
    {
    }


    /**
     * Set up a search in the current folder. Currently unused. TODO.
     *
     * @param   mixed       $query
     *
     * @return  void
     */
    public function search ($query)
    {
        if ( empty($this->_message_ids) ) {
            $this->_message_ids = $this->_imap_connection->get_message_uids($this->_mailbox_path);
        }
        $this->_message_id_idx = 0;
    }


    /**
     * Returns true if there are no messages in this folder.
     *
     * @return  boolean
     */
    public function empty ()
    {
        return $this->get_message_count() == 0;
    }


    /**
     * Return the next message in this folder and move the internal message
     * index to the next message. Returns false if there are no more messages
     * available.
     *
     * @return  mixed
     */
    public function read ()
    {
        if ( empty($this->_message_ids) && $this->message_count > 0 ) {
            //  Application forgot to call search() first, that's okay. Just
            //  return all messages in the mailbox.
            $this->_message_ids = $this->_imap_connection->get_message_uids($this->_mailbox_path);
        }
        if ( $this->_message_id_idx >= count($this->_message_ids) ) {
            return false;
        }
        return new \Asinius\Email\Message($this->_imap_connection, ['path' => $this->_mailbox_path, 'uid' => $this->_message_ids[$this->_message_id_idx++]]);
    }


    /**
     * Return the next message in this folder but do not change the internal
     * message index. If read() is called, the same message will be returned
     * again.
     *
     * @return  mixed
     */
    public function peek ()
    {
        if ( empty($this->_message_ids) && $this->message_count > 0 ) {
            //  Application forgot to call search() first, that's okay. Just
            //  return all messages in the mailbox.
            $this->_message_ids = $this->_imap_connection->get_message_uids($this->_mailbox_path);
        }
        if ( $this->_message_id_idx >= count($this->_message_ids) ) {
            return false;
        }
        return new \Asinius\Email\Message($this->_imap_connection, ['path' => $this->_mailbox_path, 'uid' => $this->_message_ids[$this->_message_id_idx]]);
    }


    /**
     * Rewind the internal message index $count messages, or back to the first
     * message.
     *
     * @param   int         $count
     *
     * @return  void
     */
    public function rewind ($count = null)
    {
        if ( is_null($count) ) {
            $count = $this->_message_id_idx;
        }
        $this->_message_id_idx = max(0, $this->_message_id_idx - $count);
    }


    /**
     * Add a new message to this folder.
     *
     * @param   mixed       $message
     *
     * @throws  \RuntimeException
     *
     * @return  boolean
     */
    public function write ($message)
    {
        if ( ! is_string($message) ) {
            if ( is_object($message) && is_a($message, '\Asinius\Email\Message') ) {
                $content = '';
                $message->print(function($chunk) use(&$content){
                    $content .= $chunk;
                });
                $message = $content;
            }
            else {
                throw new \RuntimeException("Can't write this type of message to the imap connection");
            }
        }
        $success = $this->_imap_connection->put_message($message, $this->_mailbox_path);
        if ( $success ) {
            $this->message_count++;
        }
        return $success;
    }


    /**
     * Close this folder. Currently unused. TODO.
     *
     * @return  void
     */
    public function close ()
    {
    }


    /**
     * Return the list of child folders for this folder, if any.
     *
     * @return  array
     */
    public function get_folders ()
    {
        $folders = $this->_imap_connection->get_folders($this->_mailbox_path . $this->_path_delimiter . '%');
        $this->folders = $folders;
        $this->_lock_property('folders');
        return $folders;
    }


    /**
     * Return the name for this folder. This would be the label that a user
     * would see in their email client software.
     *
     * @return  string
     */
    public function get_name ()
    {
        $name = $this->name = \Asinius\Functions::last(explode($this->_path_delimiter, $this->_mailbox_path));
        $this->name = $name;
        $this->_lock_property('name');
        return $name;
    }


    /**
     * Return the number of messages currently in this folder.
     *
     * @return  int
     */
    public function get_message_count ()
    {
        $count = $this->_imap_connection->get_message_count($this->_mailbox_path);
        $this->message_count = $count;
        $this->_lock_property('message_count');
        return $count;
    }


    /**
     * Return all of the messages in the current mailbox.
     * Do not use. Use the read() function instead to iterate over messages.
     * 
     * @deprecated
     *
     * @return  array
     */
    /*
    public function get_messages ()
    {
        if ( empty($this->_message_ids) ) {
            $this->_message_ids = $this->_imap_connection->get_message_uids($this->_mailbox_path);
        }
        $messages = [];
        foreach ($this->_message_ids as $message_id) {
            $messages[] = new \Asinius\Email\Message($this->_imap_connection, ['path' => $this->_mailbox_path, 'uid' => $message_id]);
        }
        $this->messages = $messages;
        $this->_lock_property('messages');
        return $messages;
    }
    */

}
