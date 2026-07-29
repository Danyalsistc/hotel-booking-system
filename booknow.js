document.getElementById('bookingForm').addEventListener('submit', function(event) {

    event.preventDefault();

    let isValid = true;

    const name = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const phone = document.getElementById('phone').value.trim();
    const roomType = document.getElementById('roomType').value;
    const persons = parseInt(document.getElementById('persons').value.trim(), 10);
    const checkin = document.getElementById('checkin').value.trim();

    document.getElementById('nameError').textContent = '';
    document.getElementById('emailError').textContent = '';
    document.getElementById('phoneError').textContent = '';
    document.getElementById('personsError').textContent = '';
    document.getElementById('checkinError').textContent = '';

    if (!/^[a-zA-Z]+ [a-zA-Z]+$/.test(name)) {
        isValid = false;
        document.getElementById('nameError').textContent =
        'Enter first and last name';
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        isValid = false;
        document.getElementById('emailError').textContent =
        'Enter valid email';
    }

    if (!/^0\d{9}$/.test(phone)) {
        isValid = false;
        document.getElementById('phoneError').textContent =
        'Enter valid phone number';
    }

    let maxPersons = 0;

    if(roomType === "Standard Twin Room" || roomType === "Executive Twin Room"){
        maxPersons = 2;
    }
    else if(
        roomType === "Superior Suite" ||
        roomType === "Deluxe Suite" ||
        roomType === "Executive Suite"
    ){
        maxPersons = 3;
    }
    else{
        maxPersons = 5;
    }

    if(isNaN(persons) || persons < 1 || persons > maxPersons){
        isValid = false;
        document.getElementById('personsError').textContent =
        `Maximum ${maxPersons} persons allowed`;
    }

    if(!/^\d{4}-\d{2}-\d{2}$/.test(checkin)){
        isValid = false;
        document.getElementById('checkinError').textContent =
        'Use YYYY-MM-DD format';
    }

    if(isValid){

        let bookings =
        JSON.parse(localStorage.getItem("bookings")) || [];

        let booking = {
            id: "B" + Date.now(),
            name: name,
            email: email,
            phone: phone,
            roomType: roomType,
            persons: persons,
            checkin: checkin,
            status: "Pending"
        };

        bookings.push(booking);

        localStorage.setItem(
            "bookings",
            JSON.stringify(bookings)
        );

        alert("Booking Successful!");

        window.location.href = "admin-dashboard.html";
    }

});