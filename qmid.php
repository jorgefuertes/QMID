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
 define('DATEFORMAT', "d/m/Y@H:i:s");
 define('LOGFILE', "/var/log/qmid.log");
 define('IMAP_HOST', "localhost");
 define('IMAP_PORT', "993");
 define('IMAP_USER', "jorge@jorgefuertes.com");
 define('IMAP_PASS', "que16bain");
 define('IMAP_INBOX', "INBOX");
 define('IMAP_CONN', "{".IMAP_HOST.":".IMAP_PORT."/imap/ssl/novalidate-cert}".IMAP_INBOX);

 /* Instantiate general output and error: */
 $output = new Output();
 $error  = new ErrorControl();

 $output->decir("+++ QMID DISPATCHER +++", 2);
 $inicio = time();
 $output->decir("> Started at: ".date(DATEFORMAT));

 /* New dispatcher wich parses the rules: */
 $dispatcher = new Dispatcher;

 /* Imap: */
 $imap = new imap();
 $output->decir("> ".$imap->count()." mensajes para procesar.");
 $dispatcher->imap = $imap;

 /* Process the mail */
 $dispatcher->processMailbox();

 /* END */
 unset($imap);
 $error->Salir();

 /*
  * All imap work.
  */
 class imap
 {
     private $conn;
     private $output;
     private $error;

     function __construct()
     {
         $this->output = new Output();
         $this->error  = new ErrorControl();
         # Opens imap conection:
         $this->output->decir("> Servidor: ".IMAP_CONN.".");
         $this->output->decir("> Conectando con servidor imap...", 0);
         $this->conn = imap_open (IMAP_CONN, IMAP_USER, IMAP_PASS);
         if(!$this->conn)
         {
             $this->output->decir("FALLO");
             $this->error->ErrorGrave("Error de conexión imap.");
         }
         else
         {
             $this->output->decir("OK");
         }
     }

     /*
      * Busca mensajes por su cabecera.
      * @param string $header Header to search.
      * @param string $text   Text that must be in the header.
      * @return array         Array of mensajes matching the search.
      */
     function SearchByHeader($header, $text)
     {
         $aResults = imap_search($this->conn, $header.' "'.$text.'"', FT_UID);
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
      * Cuenta los mensajes en el inbox.
      * @return integer
      */
     function count()
     {
         return imap_num_msg($this->conn);
     }

     function __destruct()
     {
         imap_close($this->conn);
         $this->output->decir("> Conexión imap cerrada.");
     }
 }


 /*
  * Clase clasificadora.
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
      * Procesado de mensajes.
      */
     function processMailbox()
     {
         if(empty($this->imap))
         {
             $this->error->ErrorGrave("Missing IMAP connection.");
         }
         else
         {
             $this->output->decir("> Executing rules.");
             foreach($this->aRules as $key => $rule)
             {
                $this->output->decir("  - Executing rule ".$key."/".count($this->aRules).": ".$rule['name']."...", 0);
                if($rule['type'] == "CAB")
                {
                    $aResults = $this->imap->SearchByHeader($rule['header'], $rule['text']);
                }
                else
                {
                    $aResults = false;
                }
                if($aResults !== false)
                {
                    $this->output->decir(count($aResults)." matches:");
                    foreach($aResults as $num => $uid)
                    {
                        $this->output->decir("    - Message: ".$uid.":");
                        $msg_overview = $this->imap->FetchOverview($uid);
                        $this->output->decir("      - FROM....: ".$msg_overview->from);
                        $this->output->decir("      - SUBJECT.: ".$msg_overview->subject);
                    }
                }
                else
                {
                    $this->output->decir("no matches.");
                }
             }
         }
     }
     
     /*
      * Load, parse and returns the rules.
      */
     function loadRules()
     {
         $this->output->decir("> Processing rules.");
	 if(file_exists(RULES))
	 {
		$aRules = array();
		$fRules = @fopen(RULES, "r");
		while (!feof($fRules))
		{
                        $row = trim(fgets($fRules, 4096));
                        if(!preg_match("/^\#|^$/", $row))
                        {
                            if(preg_match("/^.*\|.*\|.*\|.*\|.*$/", $row))
                            {
                                list($name, $type, $header, $text, $destination) = explode("|", $row);
                                $name        = trim($name);
                                $type        = trim($type);
                                $header      = trim($header);
                                $text        = trim($text);
                                $destination = trim($destination);
                                if(!preg_match("/CAB|TXT/i", $type))
                                {
                                    $this->errores->Warn("Unknown rule type: '".$row."'");
                                }
                                else
                                {
                                    $aRules[] = array(
                                                'name'        => $name,
                                                'type'        => $type,
                                                'header'      => $header,
                                                'text'        => $text,
                                                'destination' => $destination);
                                }
                            }
                            else
                            {
                                $this->errores->Warn("rule: '".$row.".");
                            }
                        }
	        }
        	fclose($fRules);
                $this->output->decir("> Process finished, ".count($aRules)." rules loaded.");
	        return $aRules;
	 }
	 else
	 {
                $this->errores->ErrorGrave("No existe el fichero de configuración.");
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
            $this->output->decir(" - [".$key.":".$rule['nombre']."]-->".$rule['destino']);
        }
    }

 }

 /*
  * Clase que se ocupa de la salida por terminal o log.
  */
 class Output
 {
    var $verbose;

    /*
     * Verbose only if we are in a tty.
     */
    function __construct()
    {
        if(posix_isatty(STDOUT))
        {
            $this->verbose = true;
        }
        else
        {
            $this->verbose = false;
        }
    }

    /*
     * Terminal and log output.
     */
    public function decir($txt, $nLF = 1, $error = false, $log = true)
    {
        # Carrige return's string:
        $rnts = "";
        while($nLF > 0)
        {
            $rtns .= "\n";
            $nLF--;
        }
        # Echo in verbose mode only:
        if($this->verbose or $error)
        {
            echo $txt.$rtns;
        }

        # Log:
        if($log)
        {
            error_log($txt.$rtns, 3, LOGFILE);
        }
    }
 }

 /*
  * Clase para gestión de errores.
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
        $this->output->decir("*** ERROR: ".$txt." ***", 2, true);
    }

    public function ErrorGrave($txt)
    {
        $this->ErrorCount++;
        $this->output->decir("*** ERROR: ".$txt." ***", 2, true);
        $this->Salir();
    }

    public function Salir()
    {
        if($this->ErrorCount == 0)
        {
            $this->output->decir("> Finalizado sin errores.", 2);
            $level = 0;
        }
        else
        {
            $this->output->decir("> Finalizado con ".$this->ErrorCount." errores.", 2, true);
            $level = 1;
        }

        exit($level);
    }
 }

 ?>
