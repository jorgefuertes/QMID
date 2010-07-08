#!/usr/bin/php
<?php
/* 
 * QMID: Queru's mail IMAP dispatcher.
 * Jorge Fuertes AKA Queru - jorge@jorgefuertes.com
 * July 2009.
 * GPL3 Lisenced.
 * @author Jorge Fuertes
 * @version 1.0
 * @package clasifica-correo-imap
 *
 */

/*
 * Configuration:
 */
 define('APP_PATH', dirname(__FILE__));
 define('RULES', APP_PATH."/qmid-rules.conf");
 require(APP_PATH.DIRECTORY_SEPARATOR."qmid.conf");
 define('IMAP_CONN',  "{".IMAP_HOST.":".IMAP_PORT.IMAP_OPTIONS."}".IMAP_INBOX);
 error_reporting(ERRORLEVEL);

 /*
  * Take command line arguments:
  * -v/--verbose: Verbose mode (defaults to log mode).
  * -d/--deliver: Deliver mode (defaults to imap client mode).
  * -l/--log:     Log to file  (defaults to yes).
  */
 foreach($argv as $key => $arg)
 {
    if(preg_match("/\-v|\-\-verbose/", $arg))
    {
        define('VERBOSE', true);
        if(defined('DELIVER'))
        {
            echo "\nERROR: Verbose mode incompatible with deliver mode.\n";
            exit(1);
        }
    }
    elseif(preg_match("/\-d|\-\-deliver/", $arg))
    {
        define('DELIVER', true);
        if(defined('VERBOSE'))
        {
            echo "\nERROR: Deliver mode incompatible with verbose mode.\n";
            exit(1);
        }            
    }
    elseif(preg_match("/\-l|\-\-log/", $arg))
    {
        define('LOG', true);
    }
    elseif(!preg_match("/qmid.php/", $arg))
    {
        echo "\nERROR: Unknown argument '".$arg."'";
        exit(1);
    }
 }
 /* Defaults: */
 if(!defined('VERBOSE')) define ('VERBOSE', true);
 if(!defined('LOG'))     define ('LOG',     true);
 if(!defined('DELIVER')) define ('DELIVER', false);

 /* Instantiate general output and error: */
 $output = new Output();
 $error  = new ErrorControl();

 $output->say("+++ QMID DISPATCHER +++", 2);
 $output->say("> Started at: ".date(DATEFORMAT));

 /* New dispatcher wich parses the rules: */
 $dispatcher = new Dispatcher;

 /* Imap: */
 $imap = new imap();
 $output->say("> ".$imap->count()." messages to process.");
 $dispatcher->imap = $imap;

 /* Process the mail */
 $dispatcher->processMailbox();

 /* END */
 unset($imap);
 $error->Finish();

 /*
  * All imap work.
  */
 class imap
 {
     private $conn;
     private $output;
     private $error;
     private $aMailboxes;

     function __construct()
     {
         $this->output = new Output();
         $this->error  = new ErrorControl();
         # Opens imap conection:
         $this->output->say("> Server: ".IMAP_CONN.".");
         $this->output->say("> Connecting to IMAP server...", 0);
         $this->conn = imap_open(IMAP_CONN, IMAP_USER, IMAP_PASS);
         if(!$this->conn)
         {
             $this->output->say("FAIL");
             $this->error->CriticalError("IMAP connection error.");
         }
         else
         {
             $this->output->say("OK");
         }

         $this->output->say("> Getting mailbox list...", 0);
         $this->aMailboxes = imap_list($this->conn, "{INBOX}", "*");
         $this->output->say(count($this->aMailboxes)." mailboxes.");
     }

     /*
      * Search messages by header contents.
      * @param string $header Header to search.
      * @param string $text   Text that must be in the header.
      * @return array         Array of messages matching the search.
      */
     function SearchByHeader($header, $text)
     {
         # Prepare the admited headers regexp:
         $imap2_headers = "/^".str_replace(", ", "$|^", IMAP2_HEADERS)."$/i";
         # Check if the header it's an admited to do imap2_search:
         if(preg_match($imap2_headers, $header))
         {
             # It's an IMAP2 admited header. We can directly look for that.
             $query = strtoupper($header).' "'.$text.'"';
         }
         else
         {
             # HEADER not admited by IMAP2 search. Need to simulate.
             #$query = 'TEXT "'.$header.": ".$text.'"';
             $query = "HEADER ".$header.' "'.$text.'"';
         }

         ###DEBUG:###
         $this->output->say("(QUERY: ".$query.")...", 0);
         ############
         $aResults = imap_search($this->conn, $query, FT_UID);
         if(count($aResults) > 0)
         {
            return $aResults;
         }
         else
         {
            return false;
         }
     }

     /*
      * Full text search.
      * @param string Text to search.
      * @return array Array of messages matching the search.
      * 
      */
     function SearchByBody($text)
     {
         # Query:
         $query = 'BODY "'.$text.'"';

         ###DEBUG:###
         $this->output->say("(QUERY: ".$query.")...", 0);
         ############
         $aResults = imap_search($this->conn, $query, FT_UID);
         if(count($aResults) > 0)
         {
            return $aResults;
         }
         else
         {
            return false;
         }
     }

     /*
      * Get msg overview.
      * @param integer $uid Message UID.
      * @return array       Message overview.
      */
     function FetchOverview($uid)
     {
         $aOverviews = imap_fetch_overview($this->conn, $uid, FT_UID);
         if($aOverviews)
         {
             return $aOverviews[0];
         }
         else
         {
             $this->error->Warn("> Cannot fetch message uid ".$uid.".");
             return false;
         }
     }

     /*
      * Cuenta los messages en el inbox.
      * @return integer
      */
     function count()
     {
         return imap_num_msg($this->conn);
     }

     /*
      * Check if a mailbox exists and create it if not.
      * @param 
      */
     function CheckAndCreateMailbox($mailbox)
     {
        return true;
        if(array_search($mailbox, $this->aMailboxes))
        {
            # Mailbox exists:
            return true;
        }
        else
        {
            # Mailbox don't exists:
            return false;
        }
     }

     /*
      * Execute a rule over an array of message uids
      * @param array $rule     The rule.
      * @param array $aResults The messages result set.
      * @return boolean        True if everything it's ok.
      */
     function ExecuteAction($rule, $aResults)
     {
        $this->output->say("    - Executing action: " . $rule['action'], 0);
        if ($rule['destination'] !== false)
        {
            $this->output->say("-->".$rule['destination'].":");
        }
        else
        {
            $this->output->say(":");
        }

        foreach($aResults as $key => $uid)
        {
            $this->output->say("      - Message id ".$uid."...", 0);
            if($rule['action'] == "MOVE")
            {
                if($this->CheckAndCreateMailbox($rule['destination']))
                {
                    $success = imap_mail_move($this->conn, $uid, $rule['destination'], CP_UID);
                }
            }
            elseif($rule['action'] == "COPY")
            {
                if($this->CheckAndCreateMailbox($rule['destination']))
                {
                    $success = imap_mail_copy($this->conn, $uid, $rule['destination'], CP_UID);
                }
            }
            elseif($rule['action'] == "DELETE")
            {
                $success = imap_delete($this->conn, $uid, FT_UID);
            }

            if($success)
            {
                $this->output->say("OK");
            }
            else
            {
                $this->output->say("FAIL");
            }
        }
     }

     function __destruct()
     {
         $this->output->say("> Cleaning mailboxes...", 0);
         imap_expunge($this->conn);
         $this->output->say("OK");
         imap_close($this->conn);
         $this->output->say("> IMAP connection closed.");
     }
 }


 /*
  * Sort and dispatching class.
  *
  */
 class Dispatcher
 {
     private $aRules;
     private $error;
     private $output;
     public  $imap;

     function __construct()
     {
         $this->output       = new Output();
         $this->error        = new ErrorControl();
         $this->aRules = $this->loadRules();
     }

     /*
      * Procesado de messages.
      */
     function processMailbox()
     {
         $start         = time();
         $matches       = 0;
         $nMessages     = $this->imap->count();

         $this->output->say("> Dispatching started at ".date(DATEFORMAT, $start).".");
         if(empty($this->imap))
         {
             $this->error->CriticalError("Missing IMAP connection.");
         }
         else
         {
             $this->output->say("> Executing rules.");
             foreach($this->aRules as $key => $rule)
             {
                $this->output->say(str_pad("  + Rule ".$key."/".count($this->aRules), 20, ".")
                        .": ".$rule['name']."...", 0);
                if($rule['type'] == "HEAD")
                {
                    /* Header based search: */
                    $aResults = $this->imap->SearchByHeader($rule['header'], $rule['text']);
                }
                elseif($rule['type'] == "TEXT")
                {
                    /* Full text search: */
                    $aResults = $this->imap->SearchByBody($rule['text']);
                }
                else
                {
                    $this->output->say("UNKNOWN RULE TYPE");
                    $aResults = false;
                }

                if($aResults !== false)
                {
                    $this->output->say(count($aResults)." matches:");
                    if(count($aResults) > 0)
                    {
                        $this->output->say("    - Matches: ", 0);
                        foreach($aResults as $num => $uid)
                        {
                            $matches++;
                            $this->output->say("[".$uid."] ", 0);
                        }
                        $this->output->say("");
                    }

                    /* Executing the rule's action: */
                    $this->imap->ExecuteAction($rule, $aResults);
                }
                else
                {
                    $this->output->say("no matches.");
                }
             }
         }
         /* End of dispatching: totals */
         $this->output->say("> END of messages.");
         $this->output->say("> TOTAL: ".$matches." matches.");
         $interval = time() - $start;
         if($interval > 59)
         {
            $minutes = intval($interval/60);
            $seconds = $interval - ($minutes * 60);
            $txt = $minutes." minutes";
            if($seconds > 0)
            {
                $txt .= " and ".$seconds." seconds";
            }
         }
         else
         {
            $txt = $interval." seconds";
         }
         $this->output->say("> ".$nMessages." messages processed in ".$txt.".");
     }

     /*
      * Load, parse and returns the rules.
      */
     function loadRules()
     {
         $this->output->say("> Processing rules.");
	 if(file_exists(RULES))
	 {
		$aRules = array();
		$fRules = @fopen(RULES, "r");
		while (!feof($fRules))
		{
                        $row = trim(fgets($fRules, 4096));
                        if(!preg_match("/^\#|^$/", $row))
                        {
                            if(preg_match("/^.*\|.*\|.*\|.*\$/", $row))
                            {
                                list($name, $type, $text, $action) = explode("|", $row);
                                $name        = trim($name);
                                $type        = trim($type);
                                $text        = trim($text);
                                $action      = trim($action);
                                if(!preg_match("/^(HEAD\:.+|TEXT)$/i", $type)
                                        or !preg_match("/^(MOVE\:.+|COPY\:.+|DELETE)$/i", $action))
                                {
                                    $this->error->Warn("Unknown rule type: '".$row."'");
                                }
                                else
                                {
                                    if(preg_match("/HEAD/i", $type))
                                    {
                                        # It's a header. Catch it.
                                        list($type_only, $header) = explode(":", $type);
                                    }
                                    else
                                    {
                                        $type_only = $type;
                                        $header = false;
                                    }

                                    if(!preg_match("/MOVE|COPY/i", $type))
                                    {
                                        list($action_only, $destination) = explode(":", $action);
                                    }
                                    else
                                    {
                                        $action_only = $action;
                                        $destination = false;
                                    }

                                    $aRules[] = array(
                                                'name'        => $name,
                                                'type'        => $type_only,
                                                'header'      => $header,
                                                'text'        => $text,
                                                'action'      => strtoupper($action_only),
                                                'destination' => $destination);
                                }
                            }
                            else
                            {
                                $this->error->Warn("rule: '".$row.".");
                            }
                        }
	        }
        	fclose($fRules);
                $this->output->say("> Process finished, ".count($aRules)." rules loaded.");
	        return $aRules;
	 }
	 else
	 {
                $this->error->CriticalError("No existe el fichero de configuración.");
		return false;
	 }
    }

    /*
     * Returns rules array.
     */
    public function getRules()
    {
        return $this->aRules;
    }
    
    /*
     * Shows a human readable list of rules:
     */
    public function showRules()
    {
        foreach($this->aRules as $key => $rule)
        {
            $this->output->say(" - [".$key.":".$rule['nombre']."]-->".$rule['destino']);
        }
    }

 }

 /*
  * Clase que se ocupa de la salida por terminal o log.
  */
 class Output
 {
    /*
     * Terminal and log output.
     */
    public function say($txt, $nLF = 1)
    {
        # Carrige return's string:
        $rtns = "";
        while($nLF > 0)
        {
            $rtns .= "\n";
            $nLF--;
        }
        # Echo in verbose mode only:
        if(VERBOSE)
        {
            echo $txt.$rtns;
        }

        # Log:
        if(LOG)
        {
            error_log($txt.$rtns, 3, LOGFILE);
        }
    }
 }

 /*
  * Error control class.
  */
 class ErrorControl
 {
    private $ErrorCount = 0;
    private $output;

    function __construct()
    {
        $this->output = new Output();
    }

    public function Warn($txt)
    {
        $this->ErrorCount++;
        $this->output->say("*** ERROR: ".$txt." ***", 2, true);
    }

    public function CriticalError($txt)
    {
        $this->ErrorCount++;
        $this->output->say("*** ERROR: ".$txt." ***", 2, true);
        $this->Finish();
    }

    public function Finish()
    {
        if($this->ErrorCount == 0)
        {
            $this->output->say("> Ended without errors.", 2);
            $level = 0;
        }
        else
        {
            $this->output->say("> Ended with ".$this->ErrorCount." errors.", 2, true);
            $level = 1;
        }

        exit($level);
    }
 }

 ?>
