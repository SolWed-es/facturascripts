document.addEventListener("DOMContentLoaded", function() {
    // Crear el elemento div para el modal
    var modal = document.createElement("div");
    modal.id = "modalOptions";
    modal.className = "modal fade";
    modal.setAttribute("role", "dialog");
    
    // Crear el contenido del modal
    var modalContent = document.createElement("div");
    modalContent.className = "modal-dialog";
    
    var modalBody = document.createElement("div");
    modalBody.className = "modal-content";
    
    var modalHeader = document.createElement("div");
    modalHeader.className = "modal-header";
    modalHeader.innerHTML = "<h4 class='modal-title'>Pago TPV</h4><button type='button' class='close' data-dismiss='modal'>&times;</button>";
    
    var modalBodyContent = document.createElement("div");
    modalBodyContent.className = "modal-body";
    modalBodyContent.innerHTML = "<p>Selecciona las facturas que se mostrarán en el listado:</p><button class='btn btn-warning m-1' onclick='window.location.href = \"ListSeleccionFacturas?vencidas=1\";'>Solo vencidas</button><button class='btn btn-primary m-1' onclick='window.location.href = \"ListSeleccionFacturas?vencidas=0\";'>Todas</button>";
    
    // Adjuntar el contenido al modal
    modalBody.appendChild(modalHeader);
    modalBody.appendChild(modalBodyContent);
    modalContent.appendChild(modalBody);
    modal.appendChild(modalContent);
    
    // Agregar el modal al final del cuerpo del documento
    document.body.appendChild(modal);
});