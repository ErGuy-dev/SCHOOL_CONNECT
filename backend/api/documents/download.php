<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/document_security.php';
doc_method('GET');doc_need('documents.download');$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$ver=filter_input(INPUT_GET,'version',FILTER_VALIDATE_INT);
if(!$id||$id<1)doc_json(400,['success'=>false,'message'=>'Identifiant invalide.']);
$d=doc_active((int)$id);if(!$d||(int)$d['est_archive']===1||!doc_can_download((int)$id))doc_json(403,['success'=>false,'message'=>'Téléchargement interdit ou document indisponible.']);
$s=db()->prepare('SELECT * FROM document_versions WHERE document_id=? AND numero_version=?');$s->execute([(int)$id,$ver?:$d['version_courante']]);$f=$s->fetch();
if(!$f||!is_file($f['chemin_stockage']))doc_json(404,['success'=>false,'message'=>'Fichier introuvable.']);
$root=realpath(DOCUMENT_STORAGE_DIR);$path=realpath($f['chemin_stockage']);
if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR))doc_json(500,['success'=>false,'message'=>'Chemin de stockage invalide.']);
audit('Téléchargement document #'.$id.' version '.$f['numero_version']);$name=str_replace(["\r","\n",'"'],'',$f['nom_original']);
header('Content-Type: '.$f['type_mime']);header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename="'.addcslashes($name,'\\'). '"; filename*=UTF-8\'\''.rawurlencode($name));header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');readfile($path);exit;
