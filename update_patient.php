<?php
include './config/connection.php';
include './common_service/common_functions.php';

$message = '';
if (isset($_POST['save_Patient'])) {
  
    $hiddenId = $_POST['hidden_id'];

    $patientName = trim($_POST['patient_name']);
    $address = trim($_POST['address']);
    $cnic = trim($_POST['cnic']);
    $age = trim($_POST['age']);
    
    $dateBirth = trim($_POST['date_of_birth']);
    $dateArr = explode("/", $dateBirth);

    $dateBirth = $dateArr[2].'-'.$dateArr[0].'-'.$dateArr[1];

    $phoneNumber = trim($_POST['phone_number']);

    $patientName = ucwords(strtolower($patientName));
    $address = ucwords(strtolower($address));

    $gender = $_POST['gender'];
    $patient_type = $_POST['patient_type'];
    $visit_purpose = $_POST['visit_purpose'];
    $marital = $_POST['marital'];

if ($patientName != '' && $address != '' && 
  $cnic != '' && $dateBirth != '' && $phoneNumber != '' && $gender != '' && $marital != '' && $patient_type != '' && $visit_purpose != '' && $age != '') {
      $query = "update `patients` 
    set `patient_name` = '$patientName', 
    `address` = '$address', 
    `patient_type` = '$patient_type',
    `visit_purpose` = '$visit_purpose',
    `marital` = '$marital',
    `age` = '$age',
    `cnic` = '$cnic', 
    `date_of_birth` = '$dateBirth', 
    `phone_number` = '$phoneNumber', 
    `gender` = '$gender' 
where `id` = $hiddenId;";
try {

  $con->beginTransaction();

  $stmtPatient = $con->prepare($query);
  $stmtPatient->execute();

  $con->commit();

  $message = 'Patient updated successfully.';

} catch(PDOException $ex) {
  $con->rollback();

  echo $ex->getMessage();
  echo $ex->getTraceAsString();
  exit;
}
}
  header("Location:congratulation.php?goto_page=patients.php&message=$message");
  exit;
}



try {
$id = $_GET['id'];
$query = "SELECT `id`, `patient_name`, `address`, 
`cnic`, date_format(`date_of_birth`, '%m/%d/%Y') as `date_of_birth`,  `phone_number`, `patient_type`, `visit_purpose`, `marital`, `age`,`gender` 
FROM `patients` where `id` = $id;";

  $stmtPatient1 = $con->prepare($query);
  $stmtPatient1->execute();
  $row = $stmtPatient1->fetch(PDO::FETCH_ASSOC);

  $gender = $row['gender'];

$dob = $row['date_of_birth']; 
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

 <?php include './config/data_tables_css.php';?>

  <link rel="stylesheet" href="plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
  <title>Update Pateint Details - Klassique Diagnoses & Clinical Services</title>

</head>
<body class="hold-transition sidebar-mini dark-mode layout-fixed layout-navbar-fixed">
<!-- Site wrapper -->
<div class="wrapper">
  <!-- Navbar -->
 <?php include './config/header.php';
include './config/sidebar.php';?>  
  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>Update Patient Details</h1>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <!-- Main content -->
    <section class="content">

      <!-- Default box -->
     <div class="card card-outline card-primary rounded-0 shadow">
        <div class="card-header">
          <h3 class="card-title">Update Patients</h3>
          
          <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
              <i class="fas fa-minus"></i>
            </button>
            
          </div>
        </div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="hidden_id" 
            value="<?php echo $row['id'];?>">
            <div class="row">
              <div class="col-lg-3 col-md-4 col-sm-4 col-xs-10">
                <label>Patient ID</label>
                <input type="text" id="cnic" name="cnic" required="required" placeholder="KDCS/MUB/12012"
                class="form-control form-control-sm rounded-0" value="<?php echo $row['cnic'];?>" />
              </div>
              <div class="col-lg-3 col-md-4 col-sm-4 col-xs-10">
              <label>Patient Full Name</label>
              <input type="text" id="patient_name" name="patient_name" required="required" placeholder="Patient Full Name"
                class="form-control form-control-sm rounded-0" value="<?php echo $row['patient_name'];?>" />
              </div>
              <br>
              <br>
              <br>
              <div class="col-lg-3 col-md-4 col-sm-4 col-xs-10">
                <div class="form-group">
                  <label>Date of Birth</label>
                    <div class="input-group date" 
                    id="date_of_birth" 
                    data-target-input="nearest">
                        <input type="text" class="form-control form-control-sm rounded-0 datetimepicker-input" data-target="#date_of_birth" name="date_of_birth" 
                        value="<?php echo $dob;?>" />
                        <div class="input-group-append" 
                        data-target="#date_of_birth" 
                        data-toggle="datetimepicker">
                            <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                    </div>
                </div>
              
              </div>
              <div class="col-lg-3 col-md-4 col-sm-4 col-xs-10">
                <label>Age</label> 
                <input type="text" id="age" name="age" required="required"  required="required" placeholder="e.g. 24"
                  class="form-control form-control-sm rounded-0" value="<?php echo $row['age'];?>" />
              </div>              
              <div class="col-lg-6 col-md-4 col-sm-4 col-xs-10">
                <label>Address</label> 
                <input type="text" id="address" name="address" required="required" placeholder="e.g. Barama, close to gipalma junction, Mubi"
                  class="form-control form-control-sm rounded-0" value="<?php echo $row['address'];?>" />
              </div>              
              
              <div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
                <label>Phone Number</label>
                <input type="text" id="phone_number" name="phone_number" required="required" placeholder="+234 (0) 812 345 6789"
                class="form-control form-control-sm rounded-0" value="<?php echo $row['phone_number'];?>" />
              </div>
              <div class="col-lg-2 col-md-4 col-sm-4 col-xs-10">
              <label>Gender</label>
                <!-- $gender -->
                <select class="form-control form-control-sm rounded-0" id="gender" name="gender">
                 <?php echo getGender($gender);?>
                </select>
              </div>
              
              <div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
                <label>Marital Status</label>
                <select class="form-control form-control-sm rounded-0" id="marital" name="marital">
                 <?php echo getMaritalStatus($marital);?>
                </select>
              </div>
              <div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
                <label>Purpose of Visit</label>
                <select class="form-control form-control-sm rounded-0" id="visit_purpose" name="visit_purpose">
                 <?php echo getVisitPurpose($visit_purpose);?>
                </select>
              </div>
              
              <div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
                <label>Patient Type</label>
                <select class="form-control form-control-sm rounded-0" id="patient_type" name="patient_type">
                 <?php echo getPatientType($visit_purpose);?>
                </select>
              </div>
              </div>
              
              <div class="clearfix">&nbsp;</div>
              <div class="row">
                <div class="col-lg-11 col-md-10 col-sm-10">&nbsp;</div>
              <div class="col-lg-1 col-md-2 col-sm-2 col-xs-2">
                <button type="submit" id="save_Patient" 
                name="save_Patient" class="btn btn-primary btn-sm btn-flat btn-block">Save</button>
              </div>
            </div>
          </form>
        </div>
        
      </div>
      
    </section>
     <br/>
     <br/>
     <br/>

 
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->
<?php 
 include './config/footer.php';

  $message = '';
  if(isset($_GET['message'])) {
    $message = $_GET['message'];
  }
?>  
  <!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->

<?php include './config/site_js_links.php'; ?>
<?php include './config/data_tables_js.php'; ?>


<script src="plugins/moment/moment.min.js"></script>
<script src="plugins/daterangepicker/daterangepicker.js"></script>
<script src="plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>

<script>
  showMenuSelected("#mnu_patients", "#mi_patients");

  var message = '<?php echo $message;?>';

  if(message !== '') {
    showCustomMessage(message);
  }
  $('#date_of_birth').datetimepicker({
        format: 'L'
    });
      
    
   $(function () {
    $("#all_patients").DataTable({
      "responsive": true, "lengthChange": false, "autoWidth": false,
      "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
    }).buttons().container().appendTo('#all_patients_wrapper .col-md-6:eq(0)');
    
  });

</script>
</body>
</html>