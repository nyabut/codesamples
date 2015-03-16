#!/usr/local/bin/php
<?php

class CitationMatcher
{
    protected $_reportersFilename = null;
    protected $_directory = null;
    protected $_pattern = null;
    protected $_matchesToOutput = array();
    protected $_outputFileHandler = null;
    protected $_unmatchedOutputFileHandler = null;
    protected $_unmatchedToOutput = array();
    
    /**
    * Constructs the object
    *
    * @param string $reportersFilename the filename including the full path
    *     to the reporters file
    * @param string $directory the full path to the directory containing the
    *     data to parse
    *
    * @return void
    */
    public function __construct($reportersFilename, $directory)
    {
        if (is_file($reportersFilename))
        {
            $this->_reportersFilename = $reportersFilename;
        }
        else
        {
            throw new Exception('The filename for the reporters file should include the path and be valid.');
        }
        
        if (is_dir($directory))
        {
            // Make sure that the directory has a trailing slash
            $this->_directory = (substr($directory, -1) != DIRECTORY_SEPARATOR) 
                ? $directory . DIRECTORY_SEPARATOR 
                : $directory;    
        }
        else
        {
            throw new Exception('The filepath for the directory containing the court decisions must be valid.');
        }
    }
    
    /**
    * Generates 2 csv files based on matching the reporters into the data directory
    *
    * Iterates over the data directory and scans the files within it for citations
    * using the reporters file to build a regex pattern.  Builds two csv files -
    * one for the matched citations and one for pin citations that could not be 
    * matched.
    *
    * @return void
    */
    public function matchInDirectory()
    {
        if ($handle = opendir($this->_directory))
        {
            // Open filehandlers for output
            $this->_outputFileHandler = fopen($this->_directory.'citations.csv', 'w');
            $this->_unmatchedOutputFileHandler = fopen($this->_directory.'unmatched_citations.csv', 'w');
            
            // Iterate through the files in the directory
            while (false !== ($filename = readdir($handle))) 
            {
                // Skip the '.' and '..' files in the directory
                if ($filename == '.' || $filename == '..')
                {
                    continue;
                }
                else
                {
                    $fileContents = file_get_contents($this->_directory . $filename);
                    if ( ! empty($fileContents))
                    {
                        $matches = $this->match($fileContents);
                        unset($fileContents);
                        $this->_exportMatches($matches, $filename);
                    }
                }
            }
            
            // Write anything that remains (too small for batch) to the files
            $this->_batchWriteCsv($this->_outputFileHandler, $this->_matchesToOutput, true);
            $this->_batchWriteCsv($this->_unmatchedOutputFileHandler, $this->_unmatchedToOutput, true);
            
            //Close file handlers
            fclose($this->_outputFileHandler);
            fclose($this->_unmatchedOutputFileHandler);
        }
    }

    /**
    * Returns the matched citations from the content
    *
    * Builds the regex pattern if necessary then runs a regex against the content
    * and only returns full matches
    *
    * @param string $content The content to run the regex against
    *
    * @return Array
    */
    public function match($content = '')
    {
        $matches = array();
        
        // Build the regex pattern
        $this->_buildRegex();
        
        preg_match_all($this->_pattern, $content, $matches);
        
        // Returns $matches[0] if it exists because those are the full matches
        return ( ! empty($matches) && count($matches[0])) ? $matches[0] : array();
    }
    
    /**
    * Builds the regex pattern and stores it in protected object variable
    *
    * Builds the regex pattern if necessary
    *
    * @return void
    */
    protected function _buildRegex()
    {
        if (is_null($this->_pattern))
        {
            if ($reportersRegexString = $this->_getReportersForRegex())
            {
                $this->_pattern = '/[0-9]+ \b'.$this->_getReportersForRegex().'[,? at ]*[0-9]+/';
            }
            else
            {
                throw new Exception(
                    'The pattern could not be generated.  Check that the reporters file is accessible.'
                );
            }
        }
        
    }
    
    /**
    * Returns a regex-friendly list of reporters to match against
    *
    * Reads in the reporters from the reporters file and then properly escapes
    * them to be regex friendly
    *
    * @return string | bool
    */
    protected function _getReportersForRegex()
    {
        $reporters = @file($this->_reportersFilename, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
        
        // We could use array_map but then we'd have to use array_fill and this is more self-evident
        foreach ($reporters as &$reporter)
        {
            $reporter = preg_quote($reporter, "'");
        }
        
        if ( ! empty($reporters))
        {
            return '('.implode('|', $reporters).')';
        }
        
        return false;
    }
    
    /**
    * Returns the canonical citations and the number of times it appears in the content
    *
    * Matches the pin citations back to the canonical if possible.  If the canonical
    * form does not exist, the pin citation is saved to be written to the file
    * containing unmatched citations.  Otherwise, if a match is found, the 
    * edge weight is incremented
    *
    * @param Array $matches   The array returned from the match() method
    * @param string $filename The filename of the file being matched against
    *
    * @return Array
    */
    protected function _consolidateMatches(&$matches = array(), $filename = '')
    {
        $consolidatedMatches = array();

        /* Map the pin cites back to the canonical citation form
           and count number of times the citation is referenced */
        foreach ($matches as $match)
        {
            // Check if this citation is a pin citation
            if (($position = strpos($match, ' at ')) !== false)
            {
                $shorthandMatch = rtrim(substr($match, 0, $position + 1));
                $pinCiteMatched = false;
                
                foreach ($consolidatedMatches as $citation => $value)
                {
                    if (strpos($citation, $shorthandMatch) !== false)
                    {
                        $consolidatedMatches[$citation] += 1;
                        $pinCiteMatched = true;
                    }
                }
                
                if ( ! $pinCiteMatched)
                {
                    $this->_unmatchedToOutput[] = array($filename, $shorthandMatch);
                }
            }
            else
            {
                $consolidatedMatches[$match] = 1;
            }
            
            unset($shortHandMatchArray);
        }
        
        unset($matches);
        
        return $consolidatedMatches;
        
    }
    
    /**
    * Writes the data to file after transforming the data into the right format
    *
    * Transforms the data into a 3-column tuple and then calls methods to batch
    * write the rows
    *
    * @param Array  $matches   The array returned from the match() method
    * @param string $filename  The name of the file being matched against
    *
    * @return void
    */
    protected function _exportMatches(&$matches = array(), $filename = '')
    {
        $citations = $this->_consolidateMatches($matches, $filename);
        
        foreach ($citations as $citation => $value)
        {
            $this->_matchesToOutput[] = array($filename, $citation, $value);
            
            $this->_batchWriteCsv($this->_outputFileHandler, $this->_matchesToOutput);
            $this->_batchWriteCsv($this->_unmatchedOutputFileHandler, $this->_unmatchedToOutput);
        }
        
        unset($citations);
        
        // If there is enough for final batches to write, we will write them
        $this->_batchWriteCsv($this->_outputFileHandler, $this->_matchesToOutput);
        $this->_batchWriteCsv($this->_unmatchedOutputFileHandler, $this->_unmatchedToOutput);
    }
    
    /**
    * Batch writes the provided rows to the specified file handler in csv format
    *
    * Builds the regex pattern if necessary then runs a regex against the content
    * and only returns full matches
    *
    * @param resource $fileHandler The resource for the file handler
    * @param Array    $rows        The rows to write if possible
    * @param bool     $force       If the rows should be written no matter the batch
    *
    * @return void
    */
    protected function _batchWriteCsv(&$fileHandler, &$rows = array(), $force = FALSE)
    {
        if (count($rows) > 500 OR $force)
        {
            foreach ($rows as $row)
            {
                @fputcsv($fileHandler, $row);
            }
            $rows = array();
            
            //gc_collect_cycles();
        }
    }
}


// Only run this when executed on the commandline
if (php_sapi_name() == 'cli')
{
    if (
        isset($argv) 
        AND 
        array_key_exists(1, $argv) 
        AND 
        array_key_exists(2, $argv) 
        AND 
        is_file($argv[1]) 
        AND 
        is_dir($argv[2])
    )
    {
        try
        {
            $matcher = new CitationMatcher($argv[1], $argv[2]);
            $matcher->matchInDirectory();
        }
        catch (Exception $e)
        {
            echo $e->getMessage();
        }
    }
    else
    {
        echo "This script uses a reporters file to scan the data folder and outputs two csv files:\n";
        echo "One file lists the citations and how many times they ocurred and the other lists\n";
        echo "the pin citations that could not be mapped back to the original citation.\n\n";
        echo "Usage: php citationMatcher.php <full path to reporters file> <full path to data folder>\n";
    }
}
