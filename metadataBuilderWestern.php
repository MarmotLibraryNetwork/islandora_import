<?php
/**
 * @param string $title
 * @param SimpleXMLElement $exportedItem
 */
function build_western_mods_data($title, $exportedItem, $repository, $recordsWithOddImageNoLog, $newEntities, $existingEntities, $entityToPID, $entitiesToRename, $entitiesToDelete){
	$mods = "<?xml version=\"1.0\"?>";
	$mods .= "<mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">";
	/*if (isset($exportedItem->collection)){
		$mods .= "<relatedItem>{$exportedItem->collection}</relatedItem>";
	}
	if (isset($exportedItem->homeloc)){
		$mods .= "<relatedItem>{$exportedItem->homeloc}</relatedItem>";
	}*/
	$mods .= "<genre>Image</genre>\r\n";
	$mods .= "<titleInfo>\r\n";
		$mods .= "<title>".htmlspecialchars($title)."</title>\r\n";
	$mods .= "</titleInfo>\r\n";

	if ((string)$exportedItem->descrip != $title){
		$mods .= "<abstract>".htmlspecialchars($exportedItem->descrip)."</abstract>\r\n";
	}

	$sterms=preg_split('/\r\n|\r|\n/', $exportedItem->sterms);
	foreach($sterms as $sterm){
		$mods .= "<subject authority='local'>\r\n";
			$mods .= "<topic>".htmlspecialchars($sterm)."</topic>\r\n";
		$mods .= "</subject>\r\n";
	}

	if (strlen($exportedItem->udf22) > 0){
		$mods .= "<note>".htmlspecialchars($exportedItem->udf22)."</note>\r\n";
	}

	$mods .= "<originInfo>\r\n";
		$date = (string)$exportedItem->date;
		$startDate = (string)$exportedItem->earlydate;
		$endDate = (string)$exportedItem->latedate;
		if (strlen($date)){
			if (preg_match('/^\d{4}\s*\?$/', $date)) {
				$mods .= "<dateCreated qualifier='approximate'>$startDate</dateCreated>\r\n";
				$mods .= "<dateCreatedEnd qualifier='approximate'>$endDate</dateCreatedEnd>\r\n";
			}elseif (preg_match('/^\d{4}\s\(\?\)$/', $date)){
				$mods .= "<dateCreated qualifier='approximate'>$startDate</dateCreated>\r\n";
				$mods .= "<dateCreatedEnd qualifier='approximate'>$endDate</dateCreatedEnd>\r\n";
			}elseif (preg_match('/^\d{4}s\s?\?$/', $date)){
				$mods .= "<dateCreated qualifier='approximate'>$startDate</dateCreated>\r\n";
				$mods .= "<dateCreatedEnd qualifier='approximate'>$endDate</dateCreatedEnd>\r\n";
			}elseif (preg_match('/^\d{4}\sor\s\d{4}$/', $date)){
				$mods .= "<dateCreated qualifier='approximate'>$startDate</dateCreated>\r\n";
				$mods .= "<dateCreatedEnd qualifier='approximate'>$endDate</dateCreatedEnd>\r\n";
			}elseif (preg_match('/^\d{4}$/', $date)){
				$mods .= "<dateCreated qualifier='approximate'>$date</dateCreated>\r\n";
			}elseif (preg_match('/^[A-Za-z]+?\s\d{1,2},\s\d{4}$/', $date)){
				$mods .= "<dateCreated>$date</dateCreated>\r\n";
			}elseif (preg_match('/^[A-Za-z]+?\s\d{1,2},\s\d{4}\s\?$/', $date)){
				$date = str_replace(' ?', '', $date);
				$mods .= "<dateCreated qualifier='questionable'>$date</dateCreated>\r\n";
			}elseif (preg_match('/^[A-Za-z]+?\s\d{4}$/', $date)){
				$mods .= "<dateCreated qualifier='approximate'>$date</dateCreated>\r\n";
			}elseif (preg_match('/^[A-Za-z]+?\s\d{4}\s\?$/', $date)){
				$date = str_replace(' ?', '', $date);
				$mods .= "<dateCreated qualifier='questionable'>$date</dateCreated>\r\n";
			}else{
				echo("Unexpected date format - $date, $startDate, $endDate<br/>\r\n");
				$mods .= "<dateCreated qualifier='approximate'>$date</dateCreated>\r\n";
			}
		}else{
			echo("No date provided - $startDate, $endDate<br/>\r\n");
		}

	$mods .= "</originInfo>\r\n";

	$mods .= "<recordInfo>\r\n";
		$mods .= "<recordOrigin>".htmlspecialchars($exportedItem->catby)."</recordOrigin>\r\n";

		$mods .= "<recordCreationDate>".htmlspecialchars($exportedItem->catdate)."</recordCreationDate>\r\n";
		if (strlen($exportedItem->maintdate) > 0){
			$maintInfo = (string)$exportedItem->maintdate;
			if (strlen($exportedItem->updatedby) > 0){
				$maintInfo .= ' ' . (string)$exportedItem->updatedby;
			}
			$mods .= "<recordChangeDate>" . htmlspecialchars($maintInfo) . "</recordChangeDate>\r\n";
		}
		if (strlen($exportedItem->updated) > 0) {
			$updated = (string)$exportedItem->updated;
			if (strlen($exportedItem->updatedby) > 0){
				$updated .= ' ' . (string)$exportedItem->updatedby;
			}
			$mods .= "<recordChangeDate>" . htmlspecialchars($updated) . "</recordChangeDate>\r\n";
		}
	$mods .= "</recordInfo>\r\n";

	$mods .= "<physicalDescription>\r\n";
		$extent = (string)$exportedItem->printsize;

		$mods .= "<extent>".htmlspecialchars($extent)."</extent>\r\n";

		$condition = '';
		if (strlen($exportedItem->conddate) > 0){
			$condition .= (string)$exportedItem->conddate;
		}
		if (strlen($exportedItem->condexam) > 0){
			if (strlen($condition) > 0) {
				$condition .= ', ';
			}
			$condition .= (string)$exportedItem->condexam;
		}
		if (strlen($exportedItem->condition) > 0){
			if (strlen($condition) > 0) {
				$condition .= ', ';
			}
			$condition .= (string)$exportedItem->condition;
		}
		if (strlen($exportedItem->condnotes) > 0){
			if (strlen($condition) > 0) {
				$condition .= ', ';
			}
			$condition = trim($condition) . ' (' . (string)$exportedItem->condnotes . ')';
		}

		if (strlen($condition) > 0) {
			$mods .= "<note type='condition'>" . htmlspecialchars($condition) . "</note>\r\n";
		}

		$formInfo = '';
		if (strlen($exportedItem->objname) > 0){
			$formInfo .= " " . (string)$exportedItem->objname;
		}
		if (strlen($exportedItem->origcopy) > 0){
			$formInfo = trim($formInfo) . " " . (string)$exportedItem->origcopy;
		}

		if (strlen($exportedItem->udf3) > 0){
			$formInfo = trim($formInfo) . " " . (string)$exportedItem->udf3;
		}

		if (strlen(trim($formInfo))){
			$mods .= "<form><formInput>\r\n";
			$mods .= htmlspecialchars($formInfo) . "\r\n";
			$mods .= "</formInput></form>\r\n";
		}
	$mods .= "</physicalDescription>\r\n";

	$mods .= "<location>\r\n";
		$physicalLocation = $exportedItem->homeloc;
		if (strlen($exportedItem->udf2) && $physicalLocation != (string)$exportedItem->udf2){
			$physicalLocation .= ', ' . (string)$exportedItem->udf2;
		}

		$mods .= "<physicalLocation>".htmlspecialchars($physicalLocation)."</physicalLocation>\r\n";

		$mods .= "<shelfLocator>".htmlspecialchars($exportedItem->udf1)."</shelfLocator>\r\n";
	$mods .= "</location>\r\n";

	$mods .= "<accessCondition>\r\n";
		$mods .= "<marmot:typeOfStatement>local</marmot:typeOfStatement>\r\n";
    $mods .= "<marmot:rightsStatement>".htmlspecialchars('Permission to use must be obtained from Leslie J. Savage Library, Western State Colorado University. Contact us at library@western.edu or 970-943-2103.')."</marmot:rightsStatement>\r\n";
	$mods .= "</accessCondition>\r\n";

	$mods .= "<extension>\r\n";
		$mods .= "<marmot:marmotLocal>\r\n";
			$mods .= "<marmot:migratedIdentifier>".htmlspecialchars($exportedItem->objectid)."</marmot:migratedIdentifier>\r\n";
			$studio = trim($exportedItem->studio);
			if (strlen($studio) > 0 && $studio != '-' && !array_key_exists($studio, $entitiesToDelete)){
				if (array_key_exists($studio, $entitiesToRename)){
					$studio = $entitiesToRename[$studio];
				}
				if (array_key_exists($studio, $entityToPID)) {
					$studioPID = $entityToPID[$studio];
				}else{
					$studioPID = createOrganization($repository, $studio, $newEntities, $existingEntities, $entityToPID);
				}
				$mods .= "<marmot:hasCreator>\r\n";
					$mods .= "<marmot:entityPid>{$studioPID}</marmot:entityPid>\r\n";
					$mods .="<marmot:entityTitle>".htmlspecialchars($studio)."</marmot:entityTitle>\r\n";
					$mods .="<marmot:role>producer</marmot:role>\r\n";
				$mods .= "</marmot:hasCreator>\r\n";
			}
			$people=preg_split('/\r\n|\r|\n/', $exportedItem->people);
			foreach($people as $person){
				$person = trim($person);
				if (strlen($person) > 0 && $person != '-' && !array_key_exists($person, $entitiesToDelete)){
					if (array_key_exists($person, $entitiesToRename)){
						$person = $entitiesToRename[$person];
					}
					$mods .= "<marmot:relatedPersonOrg>\r\n";
						if (array_key_exists($person, $entityToPID)) {
							$personPID = $entityToPID[$person];
						}else {
							$personPID = createPerson($repository, $person, $newEntities, $existingEntities, $entityToPID);
						}
						$mods .= "<marmot:entityPid>{$personPID}</marmot:entityPid>\r\n";
						$mods .="<marmot:entityTitle>".htmlspecialchars($person)."</marmot:entityTitle>\r\n";
					$mods .= "</marmot:relatedPersonOrg>\r\n";
				}
			}
			$places=preg_split('/\r\n|\r|\n/', $exportedItem->place);
			foreach ($places as $place) {
				$place = trim($place);
				if (strlen($place) > 0 && $place != '-' && !array_key_exists($place, $entitiesToDelete)){
					if (array_key_exists($place, $entitiesToRename)){
						$place = $entitiesToRename[$place];
					}
					$mods .= "<marmot:relatedPlace>\r\n";
						if (array_key_exists($place, $entityToPID)) {
							$placePID = $entityToPID[$place];
						}else {
							$placePID = createPlace($repository, $place, $newEntities, $existingEntities, $entityToPID);
						}
						$mods .= "<marmot:entityPid>{$placePID}</marmot:entityPid>\r\n";
						$mods .= "<marmot:entityTitle>" . htmlspecialchars($place)."</marmot:entityTitle>\r\n";
					$mods .= "</marmot:relatedPlace>\r\n";
				}
			}
			$mods .= "<marmot:migratedFileName>".htmlspecialchars($exportedItem->imagefile)."</marmot:migratedFileName>\r\n";

			$contextNotes = '';
			if (strlen($exportedItem->provenance) > 0){
				if (strlen($contextNotes ) > 0){
					$contextNotes .= "\r\n";
				}
				$contextNotes .= (string)$exportedItem->provenance;
			}
			//Add recas & recfrom to Context notes with prefix of acquisition notes
			if (strlen($exportedItem->recas) > 0 || strlen($exportedItem->recfrom) > 0){
				if (strlen($contextNotes ) > 0){
					$contextNotes .= "\r\n";
				}
				$contextNotes .= "Acquistion Notes: ";
				if (strlen($exportedItem->recas) > 0){
					$contextNotes .= (string)$exportedItem->recas;
				}
				if (strlen($exportedItem->recfrom) > 0){
					$contextNotes .= " from " . (string)$exportedItem->recfrom;
				}
			}

			if (strlen($contextNotes) > 0){
				$mods .= "<marmot:recordInfo><marmot:recordOrigin>".htmlspecialchars($contextNotes)."</marmot:recordOrigin></marmot:recordInfo>\r\n";
			}

			if (strlen($exportedItem->udf21) > 0){
				$mods .= "<marmot:hasTranscription><marmot:transcriptionLocation>Back of Object</marmot:transcriptionLocation><marmot:transcriptionText>".htmlspecialchars($exportedItem->udf21)."</marmot:transcriptionText></marmot:hasTranscription>\r\n";
			}
		$mods .= "</marmot:marmotLocal>\r\n";

	$mods .= "</extension>\r\n";
	$mods .= "</mods>";

	if ($exportedItem->imageno != 1){
		fwrite($recordsWithOddImageNoLog, (string)$exportedItem->objectid . "\r\n");
	}

	return $mods;
}