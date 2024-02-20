<?php
/**
 * The control file of screen module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2022 禅道软件（青岛）有限公司(ZenTao Software (Qingdao) Co., Ltd. www.cnezsoft.com)
 * @license     ZPL(http://zpl.pub/page/zplv12.html) or AGPL(https://www.gnu.org/licenses/agpl-3.0.en.html)
 * @author      Mengyi Liu <liumengyi@cnezsoft.com>
 * @package     task
 * @version     $Id: control.php 5106 2022-11-18 17:15:54Z $
 * @link        https://www.zentao.net
 */
class screen extends control
{
    /**
     * Browse screen list.
     *
     * @param  int $dimensionID
     * @access public
     * @return void
     */
    public function browse($dimensionID = 0)
    {
        $dimensionID = $this->loadModel('dimension')->setSwitcherMenu($dimensionID);
        $this->checkShowGuide();

        $screens = $this->screen->getList($dimensionID);
        $screens = $this->screen->getThumbnail($screens);
        $screens = $this->screen->removeScheme($screens);

        $this->view->title      = $this->lang->screen->common;
        $this->view->screens    = $screens;
        $this->view->dimension  = $dimensionID;
        $this->display();
    }

    /**
     * Check whether show guide.
     *
     * @access public
     * @return void
     */
    public function checkShowGuide()
    {
        $this->app->loadLang('admin');
        $this->loadModel('setting');

        $isUpdate = $this->setting->getItem("owner=system&module=bi&key=update2BI");
        if(empty($isUpdate))
        {
            $this->view->showGuide = false;
            return;
        }

        $lang     = (strpos($this->app->getClientLang(), 'zh') !== false) ? 'zh' : 'en';
        $version  = $this->config->edition == 'pms' ? 'pms' : 'biz';
        $imageURL = "static/images/bi_guide_{$version}_{$lang}.png";

        $moduleKey   = $version . 'Guide';
        $guides      = $this->setting->getItem("owner=system&module=bi&key={$moduleKey}");
        $haveSeen    = explode(',', $guides);
        $afterSeen   = array_merge($haveSeen, array($this->app->user->account));
        $this->setting->setItem("system.bi.{$moduleKey}", implode(',', array_unique($afterSeen)));

        $this->view->showGuide  = in_array($this->app->user->account, $haveSeen) ? false : true;
        $this->view->imageURL   = $imageURL;
        $this->view->version    = $version;
    }

    /**
     * View screen.
     *
     * @param  int $screenID
     * @param  int $year
     * @param  int $month
     * @param  int $dept
     * @param  string $account
     * @access public
     * @return void
     */
    public function view($screenID, $year = 0, $month = 0, $dept = 0, $account = '')
    {
        if(empty($year))  $year  = date('Y');
        if(empty($month)) $month = date('n');

        if($screenID == 3)
        {
            echo $this->fetch('report', 'annualData', "year=$year&month=$month&dept=$dept&account=$account");
            return;
        }

        $screen = $this->screen->getByID($screenID, $year, $month, $dept, $account, false);

        $this->view->title  = $screen->name;
        $this->view->screen = $screen;

        if($screenID == 5)
        {
            $this->loadModel('execution');
            $this->view->executions = $this->screen->getBurnData();
            $this->view->date       = date('Y-m-d H:i:s');
            $this->display('screen', 'burn');
        }
        else
        {
            $this->view->year    = $year;
            $this->view->month   = $month;
            $this->view->dept    = $dept;
            $this->view->account = $account;
            $this->display();
        }
    }

    public function ajaxGetScreenScheme($screenID, $year = 0, $month = 0, $dept = 0, $account = '')
    {
        $screen = $this->screen->getByID($screenID, $year, $month, $dept, $account);
        echo(json_encode($screen));
    }

    /**
     * Ajax get chart.
     *
     * @access public
     * @return void
     */
    public function ajaxGetChart()
    {
        if(!empty($_POST))
        {
            $chartID      = $this->post->sourceID;
            $type         = $this->post->type;

            if($type == 'Filters')
            {
                $filterComponent = $this->screen->genFilterComponent($chartID);
                return print(json_encode($filterComponent));
            }

            $queryType = isset($_POST['queryType']) ? $this->post->queryType : 'filter';
            $component = isset($_POST['component']) ? json_decode($this->post->component) : null;

            $type = $this->screen->getChartType($type);

            $table = $this->config->objectTables[$type];
            $chart = $this->dao->select('*')->from($table)->where('id')->eq($chartID)->fetch();

            $filterFormat = '';
            if($queryType == 'filter')
            {
                $filterParams = json_decode($this->post->filters, true);
                $filters      = json_decode($chart->filters, true);
                $mergeFilters = array();

                foreach($filters as $index => $filter)
                {
                    if($filterParams[$index]['default'] === null) continue;

                    $default = isset($filterParams[$index]['default']) ? $filterParams[$index]['default'] : null;
                    $filterType = $filter['type'];
                    if($filterType == 'date' or $filterType == 'datetime')
                    {
                        if(isset($filter['from']) and $filter['from'] == 'query')
                        {
                            if(is_numeric($default)) $default = date('Y-m-d H:i:s', $default / 1000);
                        }
                        else
                        {
                            if(is_array($default))
                            {
                                $begin = $default[0];
                                $end   = $default[1];

                                $begin = date('Y-m-d H:i:s', $begin / 1000);
                                $end = date('Y-m-d H:i:s', $end / 1000);

                                $default = array('begin' => $begin, 'end' => $end);
                            }
                            else
                            {
                                $default = array('begin' => '', 'end' => '');
                            }
                        }

                    }
                    $filter['default'] = $default;
                    $mergeFilters[] = $filter;
                }

                if($type != 'metric')
                {
                    if($table == TABLE_PIVOT)
                    {
                        list($sql, $filterFormat) = $this->loadModel($type)->getFilterFormat($chart->sql, $mergeFilters);
                        $chart->sql = $sql;
                    }
                    else
                    {
                        $filterFormat = $this->loadModel($type)->getFilterFormat($mergeFilters);
                    }
                }
            }

            if($type == 'metric')
            {
                $metric = $this->loadModel('metric')->getByID($chartID);
                $chartData = $this->screen->genMetricComponent($metric, $component, (array)$filterParams);
            }
            else
            {
                $chartData = $this->screen->genComponentData($chart, $type, $component, $filterFormat);
            }
            print(json_encode($chartData));
        }
    }

    /**
     * 获取度量数据。
     * Ajax get metric data.
     *
     * @access public
     * @return void
     */
    public function ajaxGetMetricData()
    {
        $metricID = $this->post->metricID;
        $metric   = $this->loadModel('metric')->getByID($metricID);
        $result   = $this->metric->getResultByCode($metric->code, $_POST, 'cron');

        $metricData = new stdclass();
        $metricData->header  = $this->metric->getViewTableHeader($metric);
        $metricData->data    = $this->metric->getViewTableData($metric, $result);

        echo(json_encode($metricData));
    }

    public function ajaxGetFilterOptions()
    {
        $this->loadModel('metric');

        $type         = $_POST['type'];
        $params       = json_decode($_POST['params']);
        $query        = $_POST['query'];
        $defaultValue = $_POST['defaultValue'];

        $type = $this->screen->getChartType($type);

        if($type == 'metric')
        {
            $scope = $params->typeOption;
            $objectPairs = $this->metric->getPairsByScope($scope, true);
        }
        else
        {
            if(isset($params->typeOption))
            {
                $objectPairs = $this->screen->getSysOptions($params->typeOption);
            }
            else
            {
                $sourceId = $params->sourceID;
                $field    = $params->field;
                $saveAs   = $params->saveAs;

                $table = $this->config->objectTables[$type];
                $chart = $this->dao->select('*')->from($table)->where('id')->eq($sourceId)->fetch();
                $fields = json_decode($chart->fields, true);

                $fieldObj = zget($fields, $field);
                $objectPairs = $this->screen->getSysOptions($fieldObj['type'], $fieldObj['object'], $fieldObj['field'], $chart->sql, $saveAs);
            }
        }

        $options = array_map(function($objectID, $objectName)
        {
            return array(
                'label' => $objectName,
                'value' => "$objectID"
            );
        }, array_keys($objectPairs), array_values($objectPairs));

        if(empty($query))
        {
            if(!empty($defaultValue))
            {
                $defaultValue = is_array($defaultValue) ? $defaultValue : array($defaultValue);
                $defaultOptions = array_filter($options, function($option) use($defaultValue)
                {
                    return in_array($option['value'], $defaultValue);
                });
            }

            // return limit 10 options.
            $options = array_slice($options, 0, 10);

            if(!empty($defaultOptions))
            {
                $uniqueOptions = array_udiff($options, $defaultOptions, function($a, $b)
                {
                    return $a['value'] - $b['value'];
                });
                $options = array_merge($defaultOptions, $options);
            }

            echo(json_encode($options));
            return;
        }

        $options = array_filter($options, function($option) use($query)
        {
            return strpos($option['label'], $query) !== false;
        });

        $options = array_values($options);

        echo(json_encode($options));
    }
}
