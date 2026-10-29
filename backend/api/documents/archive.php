<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/document_security.php';
doc_method('POST');$uid=doc_need('documents.archive');doc_csrf();$id=filter_var($_POST['document_id']??null,FILTER_VALIDATE_INT);$archive=filter_var($_POST['archive']??1,FILTER_VALIDATE_INT);
if(!$id||!in_array((int)$archive,[0,1],true))doc_json(422,['success'=>false,'message'=>'Paramètres invalides.']);
$s=db()->prepare('SELECT id FROM documents WHERE id=? AND supprime_le IS NULL');$s->execute([(int)$id]);if(!$s->fetchColumn())doc_json(404,['success'=>false,'message'=>'Document introuvable.']);
if((int)$archive===1){$s=db()->prepare('UPDATE documents SET est_archive=1,archive_par=?,archive_le=NOW() WHERE id=?');$s->execute([$uid,(int)$id]);audit('Archivage document #'.$id);doc_json(200,['success'=>true,'message'=>'Document archivé.']);}
$s=db()->prepare('UPDATE documents SET est_archive=0,archive_par=NULL,archive_le=NULL WHERE id=?');$s->execute([(int)$id]);audit('Désarchivage document #'.$id);doc_json(200,['success'=>true,'message'=>'Document désarchivé.']);
