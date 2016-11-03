<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * @package    AccesstoMemory
 * @author     Mike G <mikeg@artefactual.com>
 */

class arGenerateCsvReportJob extends arBaseJob
{
  /**
   * @see arBaseJob::$requiredParameters
   */
  protected $extraRequiredParameters = array('objectId', 'reportType', 'reportFormat');

  private $resource = null;
  const itemOrFileTemplatePath = 'apps/qubit/modules/informationobject/templates/itemOrFileListSuccess.php';

  private $templatePaths = array(
    'itemList' => self::itemOrFileTemplatePath,
    'fileList' => self::itemOrFileTemplatePath
  );

  public function runJob($parameters)
  {
    $this->parameters = $parameters;

    // Check that object exists and that it is not the root
    if (null === $this->resource = QubitInformationObject::getById($parameters['objectId']))
    {
      $this->error($this->i18n->__('Error: Could not find an information object with id: %1', array('%1' => $parameters['objectId'])));
      return false;
    }

    switch ($this->parameters['reportType'])
    {
      case 'fileList':
        $results = $this->getFileOrItemListResults('file');
        break;

      case 'itemList':
        $results = $this->getFileOrItemListResults('item');
        break;

      case 'storageLocations':
        break;

      case 'boxLabelCsv':
        break;

      default:
        $this->error($this->i18n->__('Invalid report type: %1', array('%1' => $parameters['reportType'])));
        return false;
    }

    $result = $this->writeReport($results, $parameters['reportType'], $this->parameters['reportFormat']);

    $this->job->setStatusCompleted();
    $this->job->save();

    return $result;
  }

  private function writeReport($results, $type, $format)
  {
    switch ($format)
    {
      case 'csv':
        $result = $this->writeCsv($results);
        break;

      case 'html':
        $result = $this->writeHtml($results, $type);
        break;

      default:
        $this->error($this->i18n->__('Invalid report format: %1', array('%1' => $format)));
        $result = false;
        break;
    }

    return $result;
  }

  private function getFilename($format)
  {
    return 'downloads/'.$this->resource->slug.'-'.$this->parameters['reportType'].'.'.$format;
  }

  private function getFileOrItemListResults($levelOfDescription)
  {
    $sortBy = isset($this->parameters['sortBy']) ? $this->parameters['sortBy'] : 'referenceCode';

    $c2 = new Criteria;
    $c2->addJoin(QubitTerm::ID, QubitTermI18n::ID, Criteria::INNER_JOIN);
    $c2->add(QubitTermI18n::NAME, $levelOfDescription);
    $c2->add(QubitTermI18n::CULTURE, 'en');
    $c2->add(QubitTerm::TAXONOMY_ID, QubitTaxonomy::LEVEL_OF_DESCRIPTION_ID);

    if (null === $lod = QubitTermI18n::getOne($c2))
    {
      throw new sfException("Can't find '$levelOfDescription' level of description in term table");
    }

    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::LFT, $this->resource->lft, Criteria::GREATER_EQUAL);
    $criteria->add(QubitInformationObject::RGT, $this->resource->rgt, Criteria::LESS_EQUAL);
    $criteria->addAscendingOrderByColumn(QubitInformationObject::LFT);

    $criteria = QubitAcl::addFilterDraftsCriteria($criteria);
    $results = array();

    if (null === $ios = QubitInformationObject::get($criteria))
    {
      return array();
    }

    foreach ($ios as $item)
    {
      if ($lod->id != $item->levelOfDescriptionId)
      {
        continue;
      }

      $parentTitle = QubitInformationObject::getStandardsBasedInstance($item->parent)->__toString();
      $creationDates = $this->getCreationDates($item);

      $results[$parentFile][] = array(
        'resource' => $item,
        'referenceCode' => QubitInformationObject::getStandardsBasedInstance($item)->referenceCode,
        'title' => $item->getTitle(array('cultureFallback' => true)),
        'dates' => isset($creationDates) ? Qubit::renderDateStartEnd($creationDates->getDate(array('cultureFallback' => true)),
                   $creationDates->startDate, $creationDates->endDate) : '',
        'startDate' => isset($creationDates) ? $creationDates->startDate : null,
        'accessConditions' => $item->getAccessConditions(array('cultureFallback' => true)),
        'locations' => $this->getLocationString($item)
      );
    }

    // Sort items by selected criteria
    uasort($results, function($a, $b) use ($sortBy) {
      return strnatcasecmp($a[$sortBy], $b[$sortBy]);
    });

    return $results;
  }

  private function writeCsv($results)
  {
    if (!count($results))
    {
      return;
    }

    if (null === $fh = fopen($this->getFilename('csv'), 'w'))
    {
      throw new sfException('Unable to open file '.$this->getFilename('csv').' - please check permissions.');
    }

    // Iterate over descriptions and their report results
    foreach ($results as $tldTitle => $items)
    {
      fputcsv($fh, array($this->i18n->__('Archival description hierarchy:')));

      // Display hierarchy leading up to the top level of description before report results for items / files
      foreach ($items[0]['resource']->getAncestors()->orderBy('lft') as $ancestor)
      {
        if ($ancestor->id != QubitInformationObject::ROOT_ID)
        {
          fputcsv($fh, array($ancestor->getTitle(array('cultureFallback' => true))));
        }
      }

      fputcsv($fh, array('---'));
      $first = true;

      // Display items or files
      foreach ($items as $row)
      {
        unset($row['resource']);

        // Write CSV header
        if ($first)
        {
          fputcsv($fh, array_keys($row));
          $first = false;
        }

        fputcsv($fh, $row);
      }
    }

    fclose($fh);
  }

  private function writeHtml($results, $type)
  {
    if (!count($results))
    {
      return;
    }

    if (null === $fh = fopen($this->getFilename('html'), 'w'))
    {
      throw new sfException('Unable to open file '.$this->getFilename('html').' - please check permissions.');
    }

    $resource = $this->resource; // Pass resource to template.

    ob_start();
    include $this->templatePaths[$type];
    $output = ob_get_clean();

    fwrite($fh, $output);
    fclose($fh);
  }

  private function getLocationString($resource)
  {
    $locations = array();
    if (null !== ($physicalObjects = $resource->getPhysicalObjects()))
    {
      foreach ($physicalObjects as $item)
      {
        $locations[] = $item->getLabel();
      }
    }

    return implode('; ', $locations);
  }

  private function getCreationDates($resource)
  {
    $creationEvents = $resource->getCreationEvents();

    if (0 == count($creationEvents) && isset($resource->parent))
    {
      return $this->getCreationDates($resource->parent);
    }
    else
    {
      foreach ($creationEvents as $item)
      {
        if (null != $item->getDate(array('cultureFallback' => true)) || null != $item->startDate)
        {
          return $item;
        }
      }
    }
  }
}
