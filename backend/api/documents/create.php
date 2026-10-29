<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/document_security.php';
doc_method('POST');$uid=doc_need('documents.upload');doc_csrf();
$title=trim((string)($_POST['titre']??''));$desc=trim((string)($_POST['description']??''));$cat=(string)($_POST['categorie']??'');
if($title===''||mb_strlen($title)>200)doc_json(422,['success'=>false,'message'=>'Titre obligatoire (200 caractères maximum).']);
if(!in_array($cat,['administratif','pedagogique'],true))doc_json(422,['success'=>false,'message'=>'Catégorie invalide.']);
if(!isset($_FILES['fichier']))doc_json(422,['success'=>false,'message'=>'Sélectionnez un fichier.']);
try{$f=doc_upload($_FILES['fichier']);$pdo=db();
 try{$pdo->beginTransaction();$s=$pdo->prepare('INSERT INTO documents(titre,description,categorie,cree_par,version_courante) VALUES(?,?,?,?,1)');$s->execute([$title,$desc?:null,$cat,$uid]);$id=(int)$pdo->lastInsertId();
 $s=$pdo->prepare('INSERT INTO document_versions(document_id,numero_version,nom_original,nom_stockage,chemin_stockage,type_mime,extension,taille_octets,sha256,ajoute_par) VALUES(?,1,?,?,?,?,?,?,?,?)');$s->execute([$id,$f['original'],$f['name'],$f['path'],$f['mime'],$f['ext'],$f['size'],$f['sha'],$uid]);$pdo->commit();}
 catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($f['path']);throw $e;}
 audit('Document créé #'.$id.' : '.$title);doc_json(201,['success'=>true,'message'=>'Document enregistré.','id'=>$id]);
}catch(Throwable $e){error_log('SchoolConnect document create: '.$e->getMessage());doc_json(422,['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Erreur lors de l’enregistrement.']);}
