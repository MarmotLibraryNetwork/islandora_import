<?php
/**
 * @param string $title
 * @param SimpleXMLElement $exportedItem
 */
function build_evld_mods_data($title, $exportedItem){
	$mods = "<?xml version=\"1.0\"?>";
	$mods .= "<mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">";
	/*if (isset($exportedItem->collection)){
		$mods .= "<relatedItem>{$exportedItem->collection}</relatedItem>";
	}
	if (isset($exportedItem->homeloc)){
		$mods .= "<relatedItem>{$exportedItem->homeloc}</relatedItem>";
	}*/
	$mods .= "<titleInfo>\r\n";
	$mods .= "<title>".htmlspecialchars($title)."</title>\r\n";
	$mods .= "<subTitle>".htmlspecialchars($exportedItem->caption)."</subTitle>\r\n";
	$mods .= "</titleInfo>\r\n";
	$mods .= "<identifier>".htmlspecialchars($exportedItem->objectid)."</identifier>\r\n";
	$mods .= "<part>".htmlspecialchars($exportedItem->imageno)."</part>\r\n";
	$mods .= "<abstract>".htmlspecialchars($exportedItem->descrip)."</abstract>\r\n";
	$mods .= "<subject authority='local'>\r\n";
	$classes=preg_split('/\r\n|\r|\n/', $exportedItem->classes);
	foreach($classes as $class){
		$mods .= "<topic>".htmlspecialchars($class)."</topic>\r\n";
	}
	$mods .= "</subject>\r\n";
	$mods .= "<subject authority='local'>\r\n";
	$sterms=preg_split('/\r\n|\r|\n/', $exportedItem->sterms);
	foreach($sterms as $sterm){
		$mods .= "<topic>".htmlspecialchars($sterm)."</topic>\r\n";
	}
	$mods .= "</subject>\r\n";
	$mods .= "<subject authority='local'>\r\n";
	$subjects=preg_split('/\r\n|\r|\n/', $exportedItem->subjects);
	foreach($subjects as $subject){
		$mods .= "<topic>".htmlspecialchars($subject)."</topic>\r\n";
	}
	$mods .= "</subject>\r\n";
	$mods .= "<originInfo>\r\n";
	$mods .= "<dateCreated>".htmlspecialchars($exportedItem->date)."</dateCreated>\r\n";
	$mods .= "<dateCreated point='start'>".htmlspecialchars($exportedItem->earlydate)."</dateCreated>\r\n";
	$mods .= "<dateCreated point='end'>".htmlspecialchars($exportedItem->latedate)."</dateCreated>\r\n";
	$mods .= "</originInfo>\r\n";
	$mods .= "<recordInfo>\r\n";
	$mods .= "<recordOrigin>".htmlspecialchars($exportedItem->catby)."</recordOrigin>\r\n";
	$mods .= "<recordCreationDate>".htmlspecialchars($exportedItem->catdate)."</recordCreationDate>\r\n";
	$mods .= "<recordChangeDate>".htmlspecialchars($exportedItem->updated)."</recordChangeDate>\r\n";
	$mods .= "<recordChangeDate>".htmlspecialchars($exportedItem->maintdate)."</recordChangeDate>\r\n";
	$mods .= "</recordInfo>\r\n";
	$mods .= "<physicalDescription>\r\n";
	$mods .= "<extent>".htmlspecialchars($exportedItem->printsize)."</extent>\r\n";
	$mods .= "<extent>".htmlspecialchars($exportedItem->filmsize)."</extent>\r\n";
	$mods .= "<extent>".htmlspecialchars($exportedItem->objname)."</extent>\r\n";
	$mods .= "<extent>".htmlspecialchars($exportedItem->origcopy)."</extent>\r\n";
	$mods .= "<extent type='medium'>".htmlspecialchars($exportedItem->medium)."</extent>\r\n";
	$mods .= "<note type='condition'>".htmlspecialchars($exportedItem->dimnotes)."</note>\r\n";
	$mods .= "</physicalDescription>\r\n";
	$mods .= "<physicalLocation>".htmlspecialchars($exportedItem->homeloc)."</physicalLocation>\r\n";
	$mods .= "<physicalLocation>".htmlspecialchars($exportedItem->collection)."</physicalLocation>\r\n";
	$mods .= "<shelfLocator>".htmlspecialchars($exportedItem->locfield1)."</shelfLocator>\r\n";
	$mods .= "<shelfLocator>".htmlspecialchars($exportedItem->locfield2)."</shelfLocator>\r\n";
	$mods .= "<shelfLocator>".htmlspecialchars($exportedItem->locfield3)."</shelfLocator>\r\n";
	$mods .= "<shelfLocator>".htmlspecialchars($exportedItem->locfield4)."</shelfLocator>\r\n";
	$mods .= "<shelfLocator>".htmlspecialchars($exportedItem->locfield5)."</shelfLocator>\r\n";
	$mods .= "<shelfLocator>".htmlspecialchars($exportedItem->locfield6)."</shelfLocator>\r\n";
	$mods .= "<shelfLocator>".htmlspecialchars($exportedItem->negloc)."</shelfLocator>\r\n";
	$mods .= "<shelfLocator>".htmlspecialchars($exportedItem->negno)."</shelfLocator>\r\n";
	$mods .= "<accessCondition><marmot:typeOfStatement>local</marmot:typeOfStatement>\r\n";
    $mods .= "<marmot:rightsStatement>".htmlspecialchars($exportedItem->copyright)."</marmot:rightsStatement>\r\n";
	$mods .= "</accessCondition>\r\n";
	$mods .= "<accessCondition><marmot:typeOfStatement>local</marmot:typeOfStatement>\r\n";
	$mods .= "<marmot:rightsStatement>".htmlspecialchars($exportedItem->legal)."</marmot:rightsStatement>\r\n";
	$mods .= "</accessCondition>\r\n";
	$mods .= "<name>\r\n";
    $mods .= "<namePart>".htmlspecialchars($exportedItem->provenance)."</namePart>\r\n";
	$mods .= "<role><roleTerm>Donor</roleTerm></role>\r\n";
	$mods .= "</name>\r\n";
	$mods .= "<extension>\r\n";
	$mods .= "<marmot:marmotLocal>\r\n";
	$mods .= "<marmot:hasPublisher>\r\n";
	$mods .= "<marmot:entityPid></marmot:entityPid>\r\n";
	$mods .= "<marmot:entityTitle>".htmlspecialchars($exportedItem->studio)."</marmot:entityTitle>\r\n";
	$mods .= "</marmot:hasPublisher>\r\n";
	$mods .= "<marmot:relatedEntity>\r\n";
	$people=preg_split('/\r\n|\r|\n/', $exportedItem->people);
	foreach($people as $person){
		$mods .= "<relatedEntity type='person'>\r\n";
		$mods .= "<marmot:entityPid></marmot:entityPid>\r\n";
		$mods .="<marmot:entityTitle>".htmlspecialchars($person)."</marmot:entityTitle>\r\n";
		$mods .= "</relatedEntity>\r\n";
	}
	$places=preg_split('/\r\n|\r|\n/', $exportedItem->place);
	foreach ($places as $place) {
		$mods .= "<relatedEntity type='place'>\r\n";
		$mods .= "<marmot:entityPid></marmot:entityPid>\r\n";
		$mods .= "<marmot:entityTitle>" . htmlspecialchars($place)."</marmot:entityTitle>\r\n";
		$mods .= "</relatedEntity>\r\n";
	}
	$events=preg_split('/\r\n|\r|\n/', $exportedItem->event);
	foreach ($events as $event) {
		$mods .= "<relatedEntity type='event'>\r\n";
		$mods .= "<marmot:entityPid></marmot:entityPid>\r\n";
		$mods .= "<marmot:entityTitle>".htmlspecialchars($event)."</marmot:entityTitle>\r\n";
		$mods .= "</relatedEntity>\r\n";
	}
	$mods .= "</marmot:relatedEntity>\r\n";
	$mods .= "</marmot:marmotLocal>\r\n";
	$mods .= "<marmot:contextNotes>".htmlspecialchars($exportedItem->notes)."</marmot:contextNotes>\r\n";
	$mods .= "<marmot:relationshipNotes>".htmlspecialchars($exportedItem->relnotes)."</marmot:relationshipNotes>\r\n";
	$mods .= "<marmot:migratedFileName>".htmlspecialchars($exportedItem->imagefile)."</marmot:migratedFileName>\r\n";
	$mods .= "</extension>\r\n";
	$mods .= "</mods>";

	return $mods;
}