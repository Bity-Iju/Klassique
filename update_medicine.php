<?php
include './config/connection.php';

 $message = '';
if(isset($_POST['save_medicine'])) {
    $medicineName = trim($_POST['medicine_name']);
    $medicineName = ucwords(strtolower($medicineName));

   $id = $_POST['hidden_id'];
    if($medicineName !== '') {

        // Use prepared statements with placeholders for secure update
        $query = "UPDATE `medicines`
        set `medicine_name` = :medicine_name
        where `id`= :id";
    try{
    	$con->beginTransaction();

    	$stmtMedicine = $con->prepare($query);
        // Bind parameters
	    $stmtMedicine->execute([':medicine_name' => $medicineName, ':id' => $id]);

	   $con->commit();

	   $message = "Record updated sucessfully.";

    }catch(PDOException $ex){
    	$con->rollback();
	    echo $ex->getMessage();
	    echo $ex->getTraceAsString();
        exit;
    }

}
header("Location:congratulation.php?goto_page=medicines.php&message=$message");
exit;
}

try {

 $id = $_GET['id'];
    // Use prepared statements for secure selection as well
	$query = "SELECT `id`, `medicine_name` from `medicines`
	          where `id` = :id";
	$stmt = $con->prepare($query);
    $stmt->execute([':id' => $id]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

} catch(PDOException $ex) {
	echo $ex->getMessage();
	echo $ex->getTraceAsString();
  	exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
 <?php include './config/site_css_links.php';?>
 <title>Update Medicine - Clinic's Patient Management System in PHP</title>

 <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
 <link rel="stylesheet" href="plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" href="plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed"> <div class="wrapper">
  <?php include './config/header.php';
include './config/sidebar.php';?>
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>Medicines</h1>
          </div>
        </div>
      </div></section>

    <section class="content">

      <div class="card card-outline card-primary rounded-0 shadow">
        <div class="card-header">
          <h3 class="card-title">Update Medicine</h3>

          <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
              <i class="fas fa-minus"></i>
            </button>

          </div>
        </div>
        <div class="card-body">
          <form method="post">
          	<div class="row">
              <input type="hidden" name="hidden_id"
              id="hidden_id" value="<?php echo $id;?>" />

          		<div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
          			<input type="text" id="medicine_name" name="medicine_name" required="required"
          			class="form-control form-control-sm rounded-0" value="<?php echo $row['medicine_name'];?>" />
          		</div>
          		<div class="col-lg-1 col-md-2 col-sm-2 col-xs-2">
          			<button type="submit" id="save_medicine"
          			name="save_medicine" class="btn btn-primary btn-sm btn-flat btn-block">Update</button>
          		</div>
          	</div>
          </form>
        </div>

        </div>
    </section>
  </div>
  <?php
 include './config/footer.php';

	$message = '';
	if(isset($_GET['message'])) {
		$message = $_GET['message'];
	}
?>
  </div>
<?php include './config/site_js_links.php'; ?>

</body>
</html>