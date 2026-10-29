<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/document_security.php';
doc_method('POST');$uid=doc_need('documents.share');doc_csrf();$id=filter_var($_POST['document_id']??null,FILTER_VALIDATE_INT);$target=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);$perm=(string)($_POST['permission']??'lecture');$expiry=trim((string)($_POST['expire_le']??''));
if(!$id||!$target||!in_array($perm,['lecture','telechargement','modification'],true))doc_json(422,['success'=>false,'message'=>'Paramètres de partage invalides.']);
if(!doc_active((int)$id))doc_json(404,['success'=>false,'message'=>'Document introuvable.']);
if($expiry!==''){$dt=DateTime::createFromFormat('Y-m-d\TH:i',$expiry);if(!$dt||$dt<=new DateTime())doc_json(422,['success'=>false,'message'=>'Expiration invalide : choisissez une date future.']);$expiry=$dt->format('Y-m-d H:i:s');}else $expiry=null;
$s=db()->prepare('SELECT id FROM users WHERE id=? AND statut="actif"');$s->execute([(int)$target]);if(!$s->fetchColumn())doc_json(422,['success'=>false,'message'=>'Destinataire absent ou désactivé.']);
$s=db()->prepare('INSERT INTO document_shares(document_id,user_id,partage_par,permission,expire_le) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE partage_par=VALUES(partage_par),permission=VALUES(permission),expire_le=VALUES(expire_le),partage_le=CURRENT_TIMESTAMP');$s->execute([(int)$id,(int)$target,$uid,$perm,$expiry]);audit('Partage document #'.$id.' avec utilisateur #'.$target.' ('.$perm.')');doc_json(200,['success'=>true,'message'=>'Document partagé.']);
