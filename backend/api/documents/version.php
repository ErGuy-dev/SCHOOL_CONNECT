<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/document_security.php';
doc_method('POST');$uid=doc_need('documents.versions');doc_csrf();$id=filter_var($_POST['document_id']??null,FILTER_VALIDATE_INT);$comment=trim((string)($_POST['commentaire']??''));
if(!$id||$id<1)doc_json(422,['success'=>false,'message'=>'Document invalide.']);
$d=doc_active((int)$id);if(!$d||(int)$d['est_archive']===1||!doc_can_version((int)$id))doc_json(403,['success'=>false,'message'=>'Ajout de version interdit.']);
if(!isset($_FILES['fichier']))doc_json(422,['success'=>false,'message'=>'Sélectionnez un fichier.']);
try{$f=doc_upload($_FILES['fichier']);$pdo=db();try{$pdo->beginTransaction();$s=$pdo->prepare('SELECT version_courante FROM documents WHERE id=? FOR UPDATE');$s->execute([(int)$id]);$cur=$s->fetchColumn();if($cur===false)throw new RuntimeException('Document introuvable.');$n=(int)$cur+1;
$s=$pdo->prepare('INSERT INTO document_versions(document_id,numero_version,nom_original,nom_stockage,chemin_stockage,type_mime,extension,taille_octets,sha256,ajoute_par,commentaire) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$s->execute([(int)$id,$n,$f['original'],$f['name'],$f['path'],$f['mime'],$f['ext'],$f['size'],$f['sha'],$uid,mb_substr($comment,0,500)?:null]);$s=$pdo->prepare('UPDATE documents SET version_courante=? WHERE id=?');$s->execute([$n,(int)$id]);$pdo->commit();}
catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($f['path']);throw $e;}audit('Nouvelle version '.$n.' document #'.$id);doc_json(201,['success'=>true,'message'=>'Nouvelle version enregistrée.','version'=>$n]);
}catch(Throwable $e){error_log('SchoolConnect document version: '.$e->getMessage());doc_json(422,['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Erreur lors de la création de version.']);}
