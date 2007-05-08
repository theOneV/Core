<?php
////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Copyright (C) 2007  Phorum Development Team                              //
//   http://www.phorum.org                                                    //
//                                                                            //
//   This program is free software. You can redistribute it and/or modify     //
//   it under the terms of either the current Phorum License (viewable at     //
//   phorum.org) or the Phorum License that was distributed with this file    //
//                                                                            //
//   This program is distributed in the hope that it will be useful,          //
//   but WITHOUT ANY WARRANTY, without even the implied warranty of           //
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.                     //
//                                                                            //
//   You should have received a copy of the Phorum License                    //
//   along with this program.                                                 //
//                                                                            //
////////////////////////////////////////////////////////////////////////////////

/**
 * This script implements the Phorum file storage API.
 *
 * A "Phorum file" is a file which is used from within Phorum as a
 * personal user file (which can be uploaded through the user's control
 * center) or as a message attachment.
 *
 * By default, the contents of a Phorum file are stored in the Phorum
 * database, but this API does support modules that change this behaviour
 * (e.g. by storing file contents on a filesystem instead).
 *
 * @package    PhorumAPI
 * @copyright  2007, Phorum Development Team
 * @license    Phorum License, http://www.phorum.org/license.txt
 */

if (!defined("PHORUM")) return;

/**
 * Function call flag, which tells {@link phorum_api_file_retrieve()}
 * that the retrieved Phorum file data has to be returned to the caller.
 */
define("PHORUM_FLAG_GET",              1);

/**
 * Function call flag, which tells {@link phorum_api_file_retrieve()}
 * that the retrieved Phorum file can be sent to the browser directly.
 */
define("PHORUM_FLAG_SEND",             2);

/**
 * Function call flag, which tells the function to skip any
 * permission checks.
 */
define("PHORUM_FLAG_IGNORE_PERMS",     4);

// A mapping of file extensions to their MIME types.
// Used by function phorum_api_file_get_mimetype().
$GLOBALS["PHORUM"]["phorum_api_file_mimetypes"] = array
(
    "pdf"  => "application/pdf",
    "doc"  => "application/msword",
    "xls"  => "application/vnd.ms-excel",
    "gif"  => "image/gif",
    "png"  => "image/png",
    "jpg"  => "image/jpeg",
    "jpeg" => "image/jpeg",
    "jpe"  => "image/jpeg",
    "tiff" => "image/tiff",
    "tif"  => "image/tiff",
    "xml"  => "text/xml",
    "mpeg" => "video/mpeg",
    "mpg"  => "video/mpeg",
    "mpe"  => "video/mpeg",
    "qt"   => "video/quicktime",
    "mov"  => "video/quicktime",
    "avi"  => "video/x-msvideo",
    "gz"   => "application/x-gzip",
    "tgz"  => "application/x-gzip",
    "zip"  => "application/zip",
    "tar"  => "application/x-tar",
    "exe"  => "application/octet-stream",
    "rar"  => "application/octet-stream",
    "wma"  => "application/octet-stream",
    "wmv"  => "application/octet-stream",
    "mp3"  => "audio/mpeg",
);

/**
 * Lookup the MIME type for a given filename.
 *
 * This will use an internal lookup list of known file extensions
 * to find the correct content type for a filename. If no content type
 * is known, then "application/octet-stream" will be used as the
 * MIME type (causing the browser to download the file, instead of
 * opening it).
 *
 * @param string $filename
 *     The filename for which to lookup the MIME type.
 *
 * @return string
 *     The MIME type for the given filename. 
 */
function phorum_api_file_get_mimetype($filename)
{
    $types = $GLOBALS["PHORUM"]["phorum_api_file_mimetypes"];

    $extension = "";
    $dotpos = strrpos($filename, ".");
    if ($dotpos !== FALSE) {
        $extension = strtolower(substr($filename, $dotpos+1));
    }

    $mime_type = isset($types[$extension]) 
               ? $types[$extension] 
               : "application/octet-stream";

    return $mime_type;
}

/**
 * Check if the user has permissions to store a personal
 * file or a message attachment.
 *
 * @param array $file
 *     This is an array, containing information about the
 *     file that will be uploaded. The array should contain at least the
 *     "link" field. That field will be used to handle checking for personal
 *     uploaded files in the control center (PHORUM_LINK_USER) or message
 *     attachments (PHORUM_LINK_MESSAGE). Next to that, interesting file
 *     fields to pass to this function are "filesize" (to check size maximums)
 *     and "filename" (to check allowed file type extensions).
 *
 * @return array
 *     If access is allowed, then TRUE will be returned. If access is denied,
 *     then FALSE will be returned and {@link phorum_api_error()} can be used
 *     to retrieve the error which describes why access was denied.
 *
 * @todo This function has not yet been fully implemented and integrated.
 */
function phorum_api_file_check_write_access($file)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Reset error storage.
    $GLOBALS["PHORUM"]["API"]["errno"] = NULL;
    $GLOBALS["PHORUM"]["API"]["error"] = NULL;

    if (!isset($file["link"])) trigger_error(
        "phorum_api_file_check_write_access(): \$file parameter needs a " .
        "\"link\" field.",
        E_USER_ERROR
    );

    $access = FALSE;

    // Check if the user has permission to upload user files.
    if ($file["link"] == PHORUM_LINK_USER)
    {
        // If file uploads are enabled, then access is granted. Access
        // is always granted to administrator users.
        $access = ($PHORUM["file_uploads"] || $PHORUM["user"]["admin"]);
        if (! $access) return phorum_api_error_set(
            PHORUM_ERRNO_NOACCESS,
            "Personal user file uploads are not enabled."
        );

        // TODO
    }

    return TRUE;
}

/** 
 * Store or update a file in the database.
 *
 * @param array $file
 *     An array, containing information for the file.
 *     This array has to contain the following fields:
 *     <ul>
 *     <li>filename: The name of the file.</li>
 *     <li>file_data: The file data.</li>
 *     <li>filesize: The size of the file data in bytes.</li>
 *     <li>link: A value describing to what type of entity the file is
 *         linked. The following values are available:
 *         <ul>
 *         <li>PHORUM_LINK_USER</li>
 *         <li>PHORUM_LINK_MESSAGE</li>
 *         <li>PHORUM_LINK_EDITOR</li>
 *         <li>PHORUM_LINK_TEMPFILE</li>
 *         </ul>
 *     </li>
 *     <li>user_id: The user to link a file to or 0 if it's no user file.</li>
 *     <li>message_id: The message to link a file to or 0 if it's no
 *         message attachment.</li>
 *     </ul>
 *
 *     Additionally, the "file_id" field can be set. If it is set,
 *     then the existing file will be updated. If it is not set,
 *     a new file will be created.
 *
 * @return mixed
 *     On error, this function will return FALSE. The function
 *     {@link phorum_api_error()} can be used to retrieve the error
 *     information.
 *
 *     On success, an array containing the data for the stored file
 *     will be returned. If the function is called with no "file_id"
 *     in the {@link $file} argument (when a new file is stored),
 *     then the new "file_id" and "add_datetime" fields will be
 *     included in the return variable as well.
 */                
function phorum_api_file_store($file)
{
    // Check if we really got an array argument for $file. 
    if (!is_array($file)) trigger_error(
        "phorum_api_file_store(): \$file parameter must be an array.",
        E_USER_ERROR
    );

    // Check and preprocess the data from the $file argument.
    // First we create a new empty file structure to fill.
    $checkfile = array(
        "user_id"     => NULL,
        "filename"    => NULL,
        "filesize"    => NULL,
        "file_data"   => NULL,
        "message_id"  => NULL,
        "link"        => NULL,
    );

    // Go over all fields in the $file argument and add these to
    // the $checkfile array.
    foreach ($file as $k => $v)
    {
        switch ($k)
        {
            case "user_id":
            case "message_id":
            case "filesize":
                if ($v !== NULL) settype($v, "int");
                $checkfile[$k] = $v;
                break;

            case "filename":
                $v = basename($v); 
                $checkfile[$k] = $v;
                break;

            case "link":
            case "file_data":
                $checkfile[$k] = $v;
                break;

            default:
                trigger_error(
                    "phorum_api_file_store(): \$file parameter contains " .
                    'an illegal field "'.htmlspecialchars($k).'".',
                    E_USER_ERROR
                );
        }

        // Force the message_id and user_id to 0, depending on the
        // link type. Also check if the correct object id is set for
        // the used link type.
        switch ($checkfile["link"])
        {
            case PHORUM_LINK_EDITOR:
                $checkfile["message_id"] = 0;
                $checkfile["user_id"] = 0;
                break;
            case PHORUM_LINK_USER: 
                $checkfile["message_id"] = 0;
                if (empty($checkfile["user_id"])) trigger_error (
                    "phorum_api_file_store(): \$file set the link type to " .
                    "PHORUM_LINK_USER, but the user_id was not set.",
                    E_USER_ERROR
                );
                break;
            case PHORUM_LINK_MESSAGE: 
                $checkfile["user_id"] = 0;
                if (empty($checkfile["message_id"])) trigger_error (
                    "phorum_api_file_store(): \$file set the link type to " .
                    "PHORUM_LINK_USER, but the message_id was not set.",
                    E_USER_ERROR
                );
                break;
        }
    }

    // See if all required values are set.
    foreach ($checkfile as $k => $v) {
        if ($v === NULL) trigger_error(
            "phorum_api_file_store(): \$file parameter misses the " .
            '"' . htmlspecialchars($k) . '" field.',
            E_USER_ERROR
        );
    }

    // All data was checked, so now we can continue with the checked data.
    $file = $checkfile;

    // Insert a skeleton file record in the database. We do this, to
    // get hold of a new file_id. That file_id can be passed on to
    // the hook below, so alternative storage systems know directly
    // for what file_id they will have to store data, without having
    // to store the full data in the database already.
    $file_id = phorum_db_file_save(array(
        "filename"   => $file["filename"],
        "filesize"   => 0,
        "file_data"  => "",
        "user_id"    => 0,
        "message_id" => 0,
        "link"       => PHORUM_LINK_TEMPFILE
    ));
    $file["file_id"] = $file_id;

    // Allow modules to handle file data storage. If a module implements
    // a different data storage method, it can store the file data in its
    // own way and set the "file_data" field to an empty string in $file
    // (it is not mandatory to do so, but it is adviceable, since it
    // would make no sense to store the file data both in an alternative
    // storage and the database at the same time).
    // The hook can use phorum_api_error_set() to return an error.
    // Hooks should be aware that their input might not be $file, but
    // FALSE instead, in which case they should immediately return
    // FALSE themselves.
    $hook_result = phorum_hook("file_store", $file);

    // Return if a module returned an error.
    if ($hook_result === FALSE)
    {
        // Cleanup the skeleton file from the database.
        phorum_db_file_delete($file["file_id"]); 
        $file["file_id"] = NULL;

        return FALSE;
    }
    $file = $hook_result;

    // Phorum stores the files in base64 format in the database, to
    // prevent problems with upgrading and migrating database servers.
    // The ASCII representation for the files will always be safe to dump
    // and restore. So here we will base64 encode the file data.
    //
    // If the file_data field is an empty string by now, then either the
    // file data was really empty to start with or a module handled the
    // storage. In both cases it's fine to keep the data field empty.
    if ($file["file_data"] != '') {
        $file["file_data"] = base64_encode($file["file_data"]);
    }
    
    // Update the skeleton file record that we created to match the real
    // file data. This acts like a commit action for the file storage.
    phorum_db_file_save ($file);

    return $file;
}

/**
 * Check if a file exists and if the user has permission to read the file.
 *
 * The function will return either an array containing descriptive data
 * for the file or FALSE, in case access was not granted.
 *
 * Note that the file_data field is not available in the return array.
 * That data can be retrieved by the {@link phorum_api_file_retrieve()}
 * function.
 *
 * @param integer $file_id
 *     The file_id of the file for which to check read access.
 *
 * @param integer $flags
 *     If the {@link PHORUM_FLAG_IGNORE_PERMS} flag is used, then permission
 *     checks are fully bypassed. In this case, the function will only check
 *     if the file exists or not.
 *
 * @return mixed
 *     On error, this function will return FALSE. The function
 *     {@link phorum_api_error()} can be used to retrieve the error
 *     information.
 *
 *     On success, it returns an array containing descriptive data for
 *     the file. The following fields are available in this array:
 *     <ul>
 *     <li>file_id: The file_id for the requested file.</li>
 *     <li>filename: The name of the file.</li>
 *     <li>filesize: The size of the file in bytes.</li> 
 *     <li>add_datetime: Epoch timestamp describing at what time 
 *         the file was stored.</li>
 *     <li>message_id: The message to which a message is linked
 *         (in case it is a message attachment).</li>
 *     <li>user_id: The user to which a message is linked 
 *         (in case it is a private user file).</li>
 *     <li>link: A value describing to what type of entity the file is
 *         linked. One of {@link PHORUM_LINK_USER},
 *         {@link PHORUM_LINK_MESSAGE}, {@link PHORUM_LINK_EDITOR} and
 *         {@link PHORUM_LINK_TEMPFILE}.</li>
 *     </ul>
 */
function phorum_api_file_check_read_access($file_id, $flags = 0)
{
    global $PHORUM;

    settype($file_id, "int");

    // Reset error storage.
    $GLOBALS["PHORUM"]["API"]["errno"] = NULL;
    $GLOBALS["PHORUM"]["API"]["error"] = NULL;

    // Check if the active user has read access for the active forum_id.
    if (!$flags & PHORUM_FLAG_IGNORE_PERMS && !phorum_check_read_common()) {
        return phorum_api_error_set(
            PHORUM_ERRNO_NOACCESS,
            "Read permission for file (id $file_id) denied."
        );
    }

    // Retrieve the descriptive file data for the file from the database.
    // Return an error if the file does not exist.
    $file = phorum_db_file_get($file_id, FALSE);
    if (empty($file)) return phorum_api_error_set(
        PHORUM_ERRNO_NOTFOUND,
        "The requested file (id $file_id) was not found."
    );

    // For the standard database based file storage, we do not have to
    // do checks for checking file existance (since the data is in the
    // database and we found the record for it). Storage modules might
    // have to do additional checks though (e.g. to see if the file data
    // exists on disk), so here we give them a chance to check for it.
    // This hook can also be used for implementing additional access
    // rules. The hook can use phorum_api_error_set() to return an error.
    // Hooks should be aware that their input might not be $file, but
    // FALSE instead, in which case they should immediately return
    // FALSE themselves.
    $file = phorum_hook("file_check_read_access", $file, $flags); 
    if ($file === FALSE) return FALSE;

    // If we do not do any permission checking, then we are done.
    if ($flags & PHORUM_FLAG_IGNORE_PERMS) return $file;

    // Is the file linked to a forum message? In that case, we have to
    // check if the message does really belong to the requested forum_id.
    if ($file["link"] == PHORUM_LINK_MESSAGE && !empty($file["message_id"]))
    {
        // Retrieve the message. If retrieving the message is not possible
        // or if the forum if of the message is different from the requested
        // forum_id, then return an error.
        $message = phorum_db_get_message($file["message_id"],"message_id",TRUE);
        if (empty($message)) return phorum_api_error_set(
            PHORUM_ERRNO_INTEGRITY,
            "An integrity problem was detected in the database: " .
            "file id $file_id is linked to non existant " .
            "message_id {$file["message_id"]}."
        );
        if ($message["forum_id"] != $PHORUM["forum_id"]) {
            return phorum_api_error_set(
                PHORUM_ERRNO_NOACCESS,
                "Permission denied for reading the file: it does not " .
                "belong to the requested forum_id {$PHORUM["forum_id"]}."
            );
        }
    }

    // A general purpose URL host matching regexp, that we'll use below.
    $matchhost = '!^https?://([^/]+)/!i';

    // See if off site links are allowed. If this is not the case, then
    // check if an off site link is requested. We use the HTTP_REFERER for
    // doing the off site link check. This is not a water proof solution
    // (since HTTP referrers can be faked), but it will be good enough for
    // stopping the majority of the off site requests.
    if (isset($_SERVER["HTTP_REFERER"]) &&
        $PHORUM["file_offsite"] != PHORUM_OFFSITE_ANYSITE &&
        preg_match($matchhost, $_SERVER["HTTP_REFERER"])) {

        // Generate the base URL for the Phorum.
        $base = strtolower(phorum_get_url(PHORUM_BASE_URL));

        // FORUMONLY: Links to forum files are only allowed from the forum.
        // Check if the referrer URL starts with the base Phorum URL.
        if ($PHORUM["file_offsite"] == PHORUM_OFFSITE_FORUMONLY) {
            $refbase = substr($_SERVER["HTTP_REFERER"], 0, strlen($base));
            if (strcasecmp($base, $refbase) != 0) return phorum_api_error_set(
                PHORUM_ERRNO_NOACCESS,
                "Permission denied: links to files in the forum are " .
                "only allowed from the forum itself."
            );
        }
        // THISSITE: Links to forum files are allowed from anywhere on
        // the website where Phorum is hosted.  
        elseif ($PHORUM["file_offsite"] == PHORUM_OFFSITE_THISSITE) {
            if (preg_match($matchhost, $_SERVER["HTTP_REFERER"], $rm) &&
                preg_match($matchhost, $base, $bm) &&
                strcasecmp($rm[1], $bm[1]) != 0) return phorum_api_error_set(
                    PHORUM_ERRNO_NOACCESS,
                    "Permission denied: links to files in the forum are " .
                    "only allowed from this web site."
            );
        }
    }

    return $file;
}

/**
 * Retrieve a Phorum file.
 *
 * This function can handle Phorum file retrieval in multiple ways:
 * either return the file to the caller or send it directly to the user's
 * browser (based on the $flags parameter). Sending it directly to the
 * browser allows for the implementation of modules that don't have to buffer
 * the full file data before sending it (a.k.a. streaming).
 *
 * @param mixed $file
 *    This is either an array containing at least the fields "file_id" 
 *    and "filename" or a numerical file_id value. Note that you can
 *    use the return value of the function
 *    {@link phorum_api_file_check_read_access()} as input for this function.
 * 
 * @param integer $flags
 *     These are flags which influence aspects of the function call. It is
 *     a bitflag value, so you can OR multiple flags together. Available
 *     flags for this function are: {@link PHORUM_FLAG_IGNORE_PERMS},
 *     {@link PHORUM_FLAG_GET} and {@link PHORUM_FLAG_SEND}. The SEND
 *     flag has precedence over the GET flag.
 * 
 * @return mixed
 *     On error, this function will return FALSE. The function
 *     {@link phorum_api_error()} can be used to retrieve the error
 *     information.
 *
 *     If the {@link PHORUM_FLAG_SEND} flag is used, then the function will
 *     return NULL.
 *
 *     If the {@link PHORUM_FLAG_GET} flag is used, then the function
 *     will return a file description array, containing the fields "file_id",
 *     "username", "file_data", "mime_type".
 *     If the {@link $file} parameter was an array, then all fields from that
 *     array will be included as well.
 */
function phorum_api_file_retrieve($file, $flags = PHORUM_FLAG_GET)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Reset error storage.
    $GLOBALS["PHORUM"]["API"]["errno"] = NULL;
    $GLOBALS["PHORUM"]["API"]["error"] = NULL;

    // If $file is not an array, we are handling a numerical file_id.
    // In that case, first retrieve the file data through the access check
    // function. All the function flags are passed on to that function,
    // so the PHORUM_FLAG_IGNORE_PERMS flag can be set for ignoring access
    // permissions.
    if (!is_array($file))
    {
        $file_id = (int) $file;
        $file = phorum_api_file_check_read_access($file_id, $flags);

        // Return in case of errors. 
        if ($file === FALSE) return FALSE;
    }

    // A small basic check to see if we have a proper $file array.
    if (!isset($file["file_id"])) trigger_error(
        "phorum_api_file_get(): \$file parameter needs a \"file_id\" field.",
        E_USER_ERROR
    );
    if (!isset($file["filename"])) trigger_error(
        "phorum_api_file_get(): \$file parameter needs a \"filename\" field.",
        E_USER_ERROR
    );
    settype($file["file_id"], "int");

    // Allow modules to handle the file data retrieval. The hook can use
    // phorum_api_error_set() to return an error. Hooks should be aware
    // that their input might not be $file, but FALSE instead, in which
    // case they should immediately return FALSE themselves.
    $file["result"]    = 0; 
    $file["mime_type"] = NULL;
    $file["file_data"] = NULL;
    $file = phorum_hook("file_retrieve", $file, $flags);
    if ($file === FALSE) return FALSE;

    // If a module sent the file data to the browser, then we are done.
    if ($file["result"] == PHORUM_FLAG_SEND) return NULL; 

    // If no module handled file retrieval, we will retrieve the
    // file from the Phorum database.
    if ($file["file_data"] === NULL)
    {
        $dbfile = phorum_db_file_get($file["file_id"], TRUE);
        if (empty($dbfile)) return phorum_api_error_set(
            PHORUM_ERRNO_NOTFOUND,
            "Phorum file (id {$file["file_id"]}) could not be " .
            "retrieved from the database."
        );

        // Phorum stores the files in base64 format in the database, to
        // prevent problems with dumping and restoring databases.
        $file["file_data"] = base64_decode($dbfile["file_data"]);
    }

    // Set the MIME type information if it was not set by a module.
    if ($file["mime_type"] === NULL) {
        $file["mime_type"] = phorum_api_file_get_mimetype($file["filename"]);
    }

    // In "send" mode, we directly send the file contents to the browser.
    if ($flags & PHORUM_FLAG_SEND)
    {
        // Get rid of any buffered output so far.
        while (ob_get_level()) ob_end_clean();

        // Avoid using any output compression or handling on the sent data.
        ini_set("zlib.output_compression", "0");
        ini_set("output_handler", "");

        header("Content-Type: " . $file["mime_type"]);
        header("Content-Disposition: filename=\"{$file["filename"]}\"");
        print $file["file_data"];

        return NULL;
    }

    // In "get" mode, we return the full file data array to the caller.
    elseif ($flags & PHORUM_FLAG_GET) {
        return $file;
    }

    // Safety net.
    else trigger_error(
        "phorum_api_file_retrieve(): no retrieve mode specified in the " .
        "flags (either use PHORUM_FLAG_GET or PHORUM_FLAG_SEND).",
        E_USER_ERROR
    );
}

/**
 * Delete a Phorum file.
 *
 * @param mixed $file
 *     This is either an array containing at least the field "file_id"
 *     or a numerical file_id value.
 */
function phorum_api_file_delete($file)
{
    // Find the file_id parameter to use.
    if (is_array($file)) {
        if (!isset($file["file_id"])) trigger_error(
            "phorum_api_file_delete(): \$file parameter needs a " .
            "\"file_id\" field.",
            E_USER_ERROR
        );
        $file_id = (int) $file["file_id"];
    } else {
        $file_id = (int) $file;
    }

    // Allow storage modules to handle the file data removal.
    // Modules should be aware of the fact that files don't have to
    // exist. The Phorum core does not throw errors when deleting a
    // non existant file. Therefore modules should accept that case
    // as well, without throwing errors.
    phorum_hook("file_delete", $file_id);

    // Delete the file from the Phorum database.
    phorum_db_file_delete($file);
}

// ------------------------------------------------------------------------
// Alias functions (useful shortcut calls to the main file api functions).
// ------------------------------------------------------------------------

/**
 * Check if a Phorum file exists.
 *
 * (this is a simple wrapper function around the
 * {@link phorum_api_file_check_read_access()} function)
 * 
 * @param integer $file_id
 *     The file_id of the Phorum file to check.
 * 
 * @return bool
 *     TRUE in case the file exists or FALSE if it doesn't.
 */
function phorum_api_file_exists($file_id) {
    $file = phorum_api_file_check_read_access($file_id, PHORUM_FLAG_IGNORE_PERMS);
    $exists = empty($file) ? FALSE : TRUE;
    return $exists;
}

/**
 * Send a file to the browser.
 *
 * (this is a simple wrapper function around the
 * {@link phorum_api_file_retrieve()} function)
 *
 * @param mixed
 *    This is either an array containing at least the fields "file_id" 
 *    and "filename" or a numerical file_id value. Note that you can
 *    use the return value of the function
 *    {@link phorum_api_file_check_read_access()} as input for this function.
 *
 * @param integer
 *     If the {@link PHORUM_FLAG_IGNORE_PERMS} flag is used, then permission
 *     checks are fully bypassed.
 *
 * @return mixed
 *     This function will always return NULL.
 */
function phorum_api_file_send($file, $flags = 0) {
    return phorum_api_file_retrieve($file, $flags | PHORUM_FLAG_SEND);
}

/**
 * Retrieve and return a Phorum file.
 * 
 * (this is a simple wrapper function around the
 * {@link phorum_api_file_retrieve()} function)
 *
 * @param mixed $file
 *    This is either an array containing at least the fields "file_id" 
 *    and "filename" or a numerical file_id value. Note that you can
 *    use the return value of the function
 *    {@link phorum_api_file_check_read_access()} as input for this function.
 *
 * @param integer $flags
 *     If the {@link PHORUM_FLAG_IGNORE_PERMS} flag is used, then permission
 *     checks are fully bypassed.
 *
 * @return mixed
 *     See the return value for the {@link phorum_api_file_retrieve()}
 *     function.
 */
function phorum_api_file_get($file, $flags = 0) {
    return phorum_api_file_retrieve($file, $flags | PHORUM_FLAG_GET);
}


?>