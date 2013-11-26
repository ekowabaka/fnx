<?php
/*
 * Copyright (c) 2011 James Ekow Abaka Ainooson
*
* Permission is hereby granted, free of charge, to any person obtaining
* a copy of this software and associated documentation files (the
    * "Software"), to deal in the Software without restriction, including
* without limitation the rights to use, copy, modify, merge, publish,
* distribute, sublicense, and/or sell copies of the Software, and to
* permit persons to whom the Software is furnished to do so, subject to
* the following conditions:
*
* The above copyright notice and this permission notice shall be
* included in all copies or substantial portions of the Software.
*
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
* EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
* MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
* NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
* LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
* OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
* WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*
*/

/**
 * A special controller class for generating reports. The report controller
 * class allows reports to be generated in different formats. The programmer only
 * has to specify the details in the Report class format and the report
 * controller would handle all the issues which have to do with reporting
 * formats. The report generator also provides report configuration forms,
 * filter fields and nested table rendering for reporting purposes.
 *
 * @ingroup Controllers
 * @author ekowabaka
 */
abstract class ReportController extends Controller
{
    /**
     * This variable is set whenever a table has already been rendered. This
     * property is used during nested table rendering.
     * @var boolean
     */
    protected $tableRendered;

    /**
     * An array which stores the widths of the columns in a nested table
     * rendering.
     * @var array
     */
    protected $widths;
    public $referencedFields;
    protected $form;
    protected $filters;
    protected $numFilters = 0;
    protected $reportData = array();
    protected $reportDataIndex = 0;
    protected $drawTotals = true;
    protected $dataParams;
    protected $script;

    public function __construct()
    {
        $this->_showInMenu = true;
    }

    /**
     * Returns an instance of the report class. The instance returned by this
     * method depends on the report options selected in the forms. The form for
     * these options are generated by the ReportController::initializeForm()
     * method.
     * @return Report
     */
    public function getReport()
    {
        switch(isset($_POST["report_format"])?$_POST["report_format"]:"pdf")
        {
            case "pdf":
                $report = new PDFReport(
                    isset($_POST["page_orientation"]) ? 
                        $_POST["page_orientation"] : 
                        PDFReport::ORIENTATION_LANDSCAPE,
                    isset($_POST["paper_size"]) ? 
                        $_POST["paper_size"] :
                        PDFReport::PAPER_A4
                );
                break;
            case "html":
                $report = new HTMLReport();
                $report->htmlHeaders = true;
                break;
            case "html-no-headers":
                $report = new HTMLReport();
                break;
            case "xls":
                $report = new XLSReport();
                break;
            case "doc":
                $report = new MSWordReport();
                break;
        }
        return $report;
    }

    /**
     * A utility function around the SQLDataStore::getMulti() method. This function
     * allows for dynamic fields. These dynamic fields are fields which are read
     * from a database table.
     * 
     * @param array $params
     * @param int $mode
     * @return array
     * @see SQLDataStore::getMulti()
     */
    public static function getReportData($params,$mode=SQLDatabaseModel::MODE_ASSOC)
    {
        $dynamicFields = array();

        if(is_array($params["dynamicHeaders"]))
        {
            foreach($params["dynamicHeaders"] as $key => $dynamicHeader)
            {
                $info = Model::resolvePath($dynamicHeader);
                $headers = Model::load($info["model"]);
                $data = $headers->get(array("fields"=>array($headers->getKeyField(),$info["field"])),SQLDataBaseModel::MODE_ARRAY);

                foreach($data as $dat)
                {
                    $replacement[$dat[1]] = null;
                }

                $dynamicFields[$key]["replacement"] = $replacement;
                $dynamicFields[$key]["headers"] = $data;
                $dynamicFields[$key]["field"] = $params["dynamicFields"][$key];
                $dynamicFields[$key]["keyField"] = count($params["fields"]);//array_search($headers->getKeyField(), $params["fields"]);
                $dynamicFields[$key]["replaceIndex"] = array_search($params["dynamicFields"][$key],$params["fields"]);
                $dynamicFields[$key]["numFields"] = count($data);
                $params["fields"][] = $headers->package.".".$headers->getKeyField();
            }
        }

        $data = SQLDBDataStore::getMulti($params, $mode);

        $numData = count($data);

        if($numData==0)
        {
            return $data;
        }
        elseif($numData==1)
        {
            foreach($dynamicFields as $dynamicField)
            {
                array_splice($data[0],$dynamicField["replaceIndex"],1,array_pad(array(),$dynamicField["numFields"],$data[0][$dynamicField["replaceIndex"]]));
            }
        }
        else
        {
            if(count($dynamicFields)==0) return $data;
            $keys = array_keys($data[0]);
            $numReturnData = 0;

            for($i = 0; $i<$numData;)
            {
                foreach($dynamicFields as $dynamicField)
                {
                    $base = $i;
                    $returnData[] = $data[$i];
                    array_splic($returnData[$numReturnData],$dynamicField["replaceIndex"],1,$dynamicField["replacement"]);

                    foreach($dynamicField["headers"] as $header)
                    {
                        for($j=0; $j<$dynamicField["numFields"];$j++) 
                        {
                            if($data[$base+$j][$dynamicField["keyField"]] == $header[0])
                            {
                                $returnData[$numReturnData][$header[1]] = $data[$base+$j][$keys[$dynamicField["replaceIndex"]]];
                                break;
                            }
                        }
                        $i++;
                    }
                    
                    unset($returnData[$numReturnData][$dynamicField["keyField"]]);
                    $numReturnData ++;
                }
            }
            $data = $returnData;
        }

        return $data;
    }

    /**
     * Draws a report table. This method could be overriden in subclasses to
     * present another means of presenting data. The method returns the total
     * values of the table in an array form based on the data parameters.
     *
     * @param array $data The data to be displayed
     * @param array $params Special parameters attached to the parameters
     * @param array $dataParams More parameters
     * @param mixed $totalTable The object to use as the instance of the totals table
     * @param string $heading A special heading for the table if it is a nested table
     * @return array
     */
    protected function drawTable($data, $params, &$dataParams, $totalTable, $heading)
    {
        $paramsCopy = $params;
        if(is_array($params["ignored_fields"]))
        {
            foreach($params["ignored_fields"] as $ignored)
            {
                unset($paramsCopy["headers"][$ignored]);
                unset($paramsCopy["data_params"]["type"][$ignored]);
                unset($paramsCopy["data_params"]["total"][$ignored]);
                unset($paramsCopy["data_params"]["widths"][$ignored]);
            }

            $paramsCopy["headers"] = array_values($paramsCopy["headers"]);
            $paramsCopy["data_params"]["type"] = array_values($paramsCopy["data_params"]["type"]);
            $paramsCopy["data_params"]["total"] = array_values($paramsCopy["data_params"]["total"]);
            $paramsCopy["data_params"]["widths"] = array_values($paramsCopy["data_params"]["widths"]);
            $this->widths = $paramsCopy["data_params"]["widths"];
            $this->dataParams = $paramsCopy["data_params"];

            foreach($data as $key => $row)
            {
                foreach($params["ignored_fields"] as $ignored)
                {
                    unset($data[$key][$ignored]);
                    //$data[$key] = array_values($row);
                }
            }
        }

        $table = new TableContent($paramsCopy["headers"],$data,$paramsCopy["data_params"]);
        if($totalTable == true)
        {
            $table->style["autoTotalsBox"] = true;
        }

        
        if($this->widths == null) $this->widths = $table->getTableWidths();

        $params["report"]->add($table);
        $total = $table->getTotals();
        
        return $total;
    }

    /**
     * Draws a heading for a nested table operation. This method could be
     * overridden by custom reports to provide a different means of presenting
     * table headings.
     * @param <type> $headingValue
     * @param <type> $params
     */
    protected function drawHeading($headingValue, &$params)
    {
        $heading = new TextContent();
        $heading->style["size"] = 12 * (( 3 - $params["grouping_level"])/3*.5+.5);
        $heading->style["bold"] = true;
        $heading->style["top_margin"] = 5 * (( 3 - $params["grouping_level"])/3);
        $heading->setText($headingValue);
        $params["report"]->add($heading);
    }
    /**
     * Take an existing table and digest it into a summary table.
     * @param unknown_type $params
     */
    protected function generateSummaryTable(&$params)
    {
    	$newFields = array($params["grouping_fields"][0]);
    	$newHeaders = array($params["headers"][array_search($params["grouping_fields"][0],$params["fields"])]);
    	$indices = array(array_search($params["grouping_fields"][0],$params["fields"]));
    	$newParams = array("total"=>array(false), "type"=>array("string"));
    	foreach($params["data_params"]['total'] as $index => $value)
    	{
    		if($value === true)
    		{
    			$tempField = $params["fields"][$index];
    		    $newFields[] = $tempField;
    		    $newHeaders[] = $params["headers"][array_search($tempField,$params["fields"])];
    		    $indices[] = array_search($tempField,$params["fields"]);
    		    $newParams["total"][] = true;
    		    $newParams["type"][] = $params["data_params"]["type"][$index];
    		}
    	}

    	$filteredData = array();
    	
    	foreach($this->reportData as $data)
    	{
    		$row = array();
    		foreach($indices as $index)
    		{
    			$row[] = $data[$index];
    		}
    		$filteredData[] = $row;
    	}
    	
    	$summarizedData = array();
    	$currentRow = $filteredData[0][0];
    	
    	for($i = 0; $i < count($filteredData); $i++)
    	{
    		$row = array();
    		$row[0] = $currentRow;
    		$add = false;
    		while($filteredData[$i][0] == $currentRow)
    		{
    			for($j = 1; $j < count($indices); $j++)
    			{
    				$add = true;
    				$row[$j] += str_replace(",", "", $filteredData[$i][$j]);
    			}
    			$i++;
    		}
    		if($add) $summarizedData[] = $row;
    		$currentRow = $filteredData[$i][0];
            $i--;
    	}
        $table = new TableContent($newHeaders, $summarizedData, $newParams);
        $table->style["autoTotalsBox"] = true;
        $params["report"]->add($table);
    }

    /**
     * Recursively generates tables based on grouping parameters. The method
     * is called in a nested fashion hence the grouping can also be nested.
     * 
     * @param Array $params
     * @return Array
     */
    protected function generateTable(&$params)
    {
        $groupingField = array_search($params["grouping_fields"][$params["grouping_level"]],$params["fields"]);
        $groupingLevel = $params["grouping_level"];
        $accumulatedTotals = array();
        
        if(count($this->reportData) == 0) return;
        
        do
        {
            if($_POST["grouping_".($params["grouping_level"]+1)."_newpage"] == "1")
            {
                $params["report"]->addPage($_POST["grouping_".($params["grouping_level"]+1)."_newpage"]);
            }

            $headingValue = $this->reportData[$this->reportDataIndex][$groupingField];
            $this->drawHeading($headingValue, $params);

            $totalsBox = new TableContent($params["headers"],null);
            $totalsBox->style["totalsBox"] = true;
            array_unshift($params["previous_headings"], array($headingValue, $groupingField));
            $params["ignored_fields"][] = $groupingField;

            if($params["grouping_fields"][$groupingLevel + 1] == "")
            {
                $data = array();
                do
                {
                    //if($t == 1000) die(); $t++;
                    $continue = true;
                    $row = $this->reportData[$this->reportDataIndex];
                    //var_dump($row);

                    @$data[] = array_values($row);

                    $this->reportDataIndex++;

                    foreach($params["previous_headings"] as $heading)
                    {
                        if($heading[0] != $this->reportData[$this->reportDataIndex][$heading[1]])
                        {
                            array_shift($params["previous_headings"]);
                            $continue = false;
                            break;
                        }
                    }
                }while($continue);
                
                $totals = $this->drawTable($data, $params, $params["data_params"], null, $headingValue);
                array_pop($params["ignored_fields"]);
            }
            else
            {
                $params["grouping_level"]++;
                $totals = $this->generateTable($params);
                array_shift($params["previous_headings"]);
                $params["grouping_level"]--;
                array_pop($params["ignored_fields"]);
            }

            if($this->drawTotals && $totals != null)
            {
                $totalsBox->data_params = $this->dataParams;
                $totalsBox->data_params["widths"] = $this->widths;
                $totals[0] = "$headingValue";
                $totalsBox->setData($totals);
                $params["report"]->add($totalsBox);
                foreach($totals as $i => $total)
                {
                    if($total === null) continue;
                    $accumulatedTotals[$i] += $total;
                }
            }

            if($params["previous_headings"][0][0] != $this->reportData[$this->reportDataIndex][$params["previous_headings"][0][1]])
            {
                break;
            }

        }while($this->reportDataIndex < count($this->reportData));
        
        return $accumulatedTotals;        
    }

    public function getPermissions()
    {
        return array
        (
            array("label"=>"Can view","name"=>substr(str_replace("/", "_", $this->path),1)."_can_view"),
        );
    }

    public function getContents()
    {
        $form = $this->getForm();
        $data = array
            (
            "script"=>$this->script,
            "filters"=>$form->render()
        );
        return array("template"=>"file:".getcwd()."/lib/controllers/reports.tpl","data"=>$data);
    }

    /**
     * Initializes a form for reports. The form generate already contains options
     * which are standard to all reports. The initialized form is accessible
     * through the ReportController::form variable.
     */
    protected function initializeForm()
    {
        $this->form = new Form();
        $this->form->add(Element::create("FieldSet","Report Format")->add
            (
                Element::create("SelectionList", "File Format", "report_format")
                    ->addOption("Hypertext Markup Language (HTML)","html")
                    ->addOption("Portable Document Format (PDF)","pdf")
                    ->addOption("Microsoft Excel (XLS)","xls")
                    ->addOption("Microsoft Word (DOC)","doc")
                    ->setRequired(true)
                    ->setValue("pdf"),
                Element::create("SelectionList", "Page Orientation", "page_orientation")
                    ->addOption("Landscape", "L")
                    ->addOption("Portrait", "P")
                    ->setValue("L"),
                Element::create("SelectionList", "Paper Size", "paper_size")
                    ->addOption("A4", "A4")
                    ->addOption("A3", "A3")
                    ->setValue("A4")
            )->setId("report_formats")->addAttribute("style","width:50%")
        );
        $this->form->setSubmitValue("Generate");
        $this->form->addAttribute("action",Application::getLink($this->path."/generate"));
        $this->form->addAttribute("target","blank");
    }

    /**
     * Initializes the filters on the form.
     * @param integer $numFilters The total number of filters the form would display
     */
    protected function initializeFilters($numFilters)
    {
        $this->filters = new TableLayout($numFilters,4);
        $this->form->add($this->filters);
    }

    /**
     * Add a date filter to the form.
     * @param string $label A label for the filter
     * @param string $name  A machine readable name for the filter 
     */
    protected function addDateFilter($label,$name,$opt=null)
    {
        $this->filters
            ->add(Element::create("Label",$label),$this->numFilters,0)
            ->add(Element::create("SelectionList","","{$name}_option")
                ->addOption("Before","LESS")
                ->addOption("After","GREATER")
                ->addOption("On","EQUALS")
                ->addOption("Between","BETWEEN")
                ->setValue($opt),$this->numFilters,1)
            ->add(Element::create("DateField","","{$name}_start_date")->setId("{$name}_start_date"),$this->numFilters,2)
            ->add(Element::create("DateField","","{$name}_end_date")->setId("{$name}_end_date"),$this->numFilters,3);
            $this->numFilters++;
    }
    
    protected function addFieldFilter($label, $field, $options = array())
    {
        $this->filters->add(Element::create("Label", $label), $this->numFilters, 0);
        $this->filters->add($field, $this->numFilters, 2);
        $this->numFilters++;
    }

    protected function addReferencedFilter($label,$name,$model,$value,$searchField = true)
    {
        if($searchField === true || is_array($searchField))
        {
            if(is_array($searchField))
            {
                $enum_list = new ModelSearchField($model,$value);
            	foreach($searchField as $field)
                {
            	   $enum_list->addSearchField($field);
                }
                $enum_list->boldFirst = true;
            }
            else
            {
                $enum_list = new ModelSearchField($model,$value);
                $enum_list->boldFirst = false;
            }
        }
        else
        {
            $enum_list = new ModelField($model,$value);
        }
        $enum_list->setName("{$name}_value");
        $this->filters
            ->add(Element::create("Label",$label),$this->numFilters,0)
            ->add(Element::create("SelectionList","","{$name}_option")
                ->addOption("Is any of","IS_ANY_OF")
                ->addOption("Is none of","IS_NONE_OF")
                ->setValue("IS_ANY_OF"),$this->numFilters,1)
            ->add(Element::create("MultiFields")->setTemplate($enum_list),$this->numFilters,2);
        $this->numFilters++;
    }
    
    protected function addEnumerationFilter($label, $name, $options)
    {
        $enum_list = new SelectionList("","{$name}_value");
        $enum_list->setMultiple(true);
        
        foreach($options as $value =>$label) 
        {
            $enum_list->addOption($label,$value);
        }
        
        $this->filters
            ->add(Element::create("Label",str_replace("\\n"," ",$label)),$this->numFilters,0)
            ->add(Element::create("SelectionList","","{$name}_option")
            ->addOption("Is any of","INCLUDE")
            ->addOption("Is none of","EXCLUDE")
            ->setValue("INCLUDE"),$this->numFilters,1)
            ->add($enum_list,$this->numFilters,2);
        $this->numFilters++;
    }
    
    public abstract function getForm();
    
}
