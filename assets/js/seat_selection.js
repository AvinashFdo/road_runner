let selectedSeats = new Set();
let passengers = [];
let passengerCounter = 0;
let basePricePerSeat = 0; // Will be set by the page

// Initialize the seat selection system
function initSeatSelection(basePrice, accountHolderName) {
    basePricePerSeat = basePrice;

    // Initialize with one passenger
    addPassenger(accountHolderName);

    // Initialize form validation
    setupFormValidation();
}

function selectSeat(seatElement) {
    // Check if seat is already booked
    if (seatElement.hasAttribute('data-booked')) {
        alert('This seat is already booked. Please select another seat.');
        return;
    }

    const seatId = seatElement.getAttribute('data-seat-id');
    const seatNumber = seatElement.getAttribute('data-seat-number'); // Now this is just a simple number like "1", "2", "3"

    if (seatElement.classList.contains('selected')) {
        // Deselect seat
        seatElement.classList.remove('selected');
        selectedSeats.delete(seatId);

        // Remove from passengers who had this seat
        passengers.forEach(passenger => {
            if (passenger.seatId === seatId) {
                passenger.seatId = null;
                passenger.seatNumber = null;
                updatePassengerDisplay(passenger.id);
            }
        });
    } else {
        // Check if we have passengers without seats
        const passengerWithoutSeat = passengers.find(p => !p.seatId);
        if (!passengerWithoutSeat) {
            alert('All passengers already have seats. Add more passengers or deselect a seat first.');
            return;
        }

        // Select seat
        seatElement.classList.add('selected');
        selectedSeats.add(seatId);

        // Assign to first passenger without a seat
        passengerWithoutSeat.seatId = seatId;
        passengerWithoutSeat.seatNumber = seatNumber; // Simple number
        updatePassengerDisplay(passengerWithoutSeat.id);
    }

    updateBookingButton();
    updateSeatColors();
}

function addPassenger(defaultName = '') {
    passengerCounter++;
    const passengerId = 'passenger_' + passengerCounter;

    const passenger = {
        id: passengerId,
        name: defaultName || '',
        gender: '',
        seatId: null,
        seatNumber: null, // Actual seat number (A01, B02, etc.)
        displayNumber: null // Display number (1, 2, 3, etc.)
    };

    passengers.push(passenger);

    const passengerIndex = passengers.length - 1;
    const passengerHtml = `
        <div class="passenger_form" id="${passengerId}">
            <div class="passenger_header">
                <span class="passenger_number">Passenger ${passengers.length}</span>
                <span class="seat_status">
                    <span class="seat_indicator" style="display: none;">No seat selected</span>
                </span>
                ${passengers.length > 1 ? `<button type="button" class="remove_passenger" onclick="removePassenger('${passengerId}')" title="Remove passenger">&times;</button>` : ''}
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                <input 
                    type="text" 
                    name="passengers[${passengerIndex}][name]" 
                    placeholder="Full Name" 
                    class="form_control" 
                    value="${passenger.name}" 
                    required 
                    onchange="updatePassengerData('${passengerId}', 'name', this.value)"
                    data-passenger-id="${passengerId}"
                >
                <select 
                    name="passengers[${passengerIndex}][gender]" 
                    class="form_control" 
                    required 
                    onchange="updatePassengerData('${passengerId}', 'gender', this.value); updateSeatColors();"
                    data-passenger-id="${passengerId}"
                >
                    <option value="">Select Gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
            </div>
            
            <input type="hidden" name="passengers[${passengerIndex}][seat_id]" value="" data-passenger-id="${passengerId}">
        </div>
    `;

    document.getElementById('passengers_container').insertAdjacentHTML('beforeend', passengerHtml);
    updatePassengerCount();
    updateBookingButton();
}

function removePassenger(passengerId) {
    const passenger = passengers.find(p => p.id === passengerId);
    if (passenger && passenger.seatId) {
        // Remove seat selection
        const seatElement = document.querySelector(`[data-seat-id="${passenger.seatId}"]`);
        if (seatElement) {
            seatElement.classList.remove('selected');
        }
        selectedSeats.delete(passenger.seatId);
    }

    // Remove from passengers array
    passengers = passengers.filter(p => p.id !== passengerId);

    // Remove from DOM
    const passengerElement = document.getElementById(passengerId);
    if (passengerElement) {
        passengerElement.remove();
    }

    // Reindex form inputs
    reindexPassengerForms();
    updatePassengerCount();
    updateBookingButton();
    updateSeatColors();
}

function updatePassengerData(passengerId, field, value) {
    const passenger = passengers.find(p => p.id === passengerId);
    if (passenger) {
        passenger[field] = value;

        // If gender changed, update seat colors
        if (field === 'gender') {
            updateSeatColors();
        }
    }
}

function updatePassengerDisplay(passengerId) {
    const passenger = passengers.find(p => p.id === passengerId);
    const passengerElement = document.getElementById(passengerId);

    if (passenger && passengerElement) {
        const seatStatus = passengerElement.querySelector('.seat_status .seat_indicator');
        const hiddenSeatInput = passengerElement.querySelector('input[name*="[seat_id]"]');

        if (passenger.seatId) {
            // Show simple seat number
            seatStatus.textContent = `Seat ${passenger.seatNumber}`;
            seatStatus.style.display = 'inline-block';
            seatStatus.style.background = '#4caf50';
            seatStatus.style.color = 'white';
            seatStatus.style.padding = '0.25rem 0.5rem';
            seatStatus.style.borderRadius = '4px';
            hiddenSeatInput.value = passenger.seatId;
            passengerElement.classList.add('has_seat');
        } else {
            seatStatus.textContent = 'No seat selected';
            seatStatus.style.display = 'none';
            seatStatus.style.background = '';
            seatStatus.style.color = '';
            seatStatus.style.padding = '';
            seatStatus.style.borderRadius = '';
            hiddenSeatInput.value = '';
            passengerElement.classList.remove('has_seat');
        }
    }
}

function reindexPassengerForms() {
    const passengerForms = document.querySelectorAll('.passenger_form');
    passengerForms.forEach((form, index) => {
        const inputs = form.querySelectorAll('input, select');
        inputs.forEach(input => {
            if (input.name && input.name.includes('passengers[')) {
                // Extract the field name properly
                const nameMatch = input.name.match(/passengers\[\d+\]\[(\w+)\]/);
                if (nameMatch) {
                    const fieldName = nameMatch[1];
                    input.name = `passengers[${index}][${fieldName}]`;
                }
            }
        });

        const passengerNumber = form.querySelector('.passenger_number');
        if (passengerNumber) {
            passengerNumber.textContent = `Passenger ${index + 1}`;
        }
    });
}

function updatePassengerCount() {
    const countElement = document.getElementById('passenger_count');
    const totalElement = document.getElementById('total_amount');

    if (countElement) {
        countElement.textContent = passengers.length;
    }

    if (totalElement) {
        const totalAmount = passengers.length * basePricePerSeat;
        totalElement.textContent = 'LKR ' + totalAmount.toLocaleString();
    }
}

function updateBookingButton() {
    const bookButton = document.getElementById('book_button');
    if (!bookButton) return;

    const allPassengersHaveSeats = passengers.length > 0 && passengers.every(p => p.seatId);
    const allPassengersHaveGender = passengers.length > 0 && passengers.every(p => p.gender);
    const allPassengersHaveNames = passengers.length > 0 && passengers.every(p => p.name.trim() !== '');

    if (passengers.length === 0) {
        bookButton.disabled = true;
        bookButton.textContent = 'Add Passengers First';
    } else if (!allPassengersHaveNames) {
        bookButton.disabled = true;
        bookButton.textContent = 'Enter Names for All Passengers';
    } else if (!allPassengersHaveGender) {
        bookButton.disabled = true;
        bookButton.textContent = 'Select Gender for All Passengers';
    } else if (!allPassengersHaveSeats) {
        bookButton.disabled = true;
        bookButton.textContent = `Select Seats for All Passengers (${selectedSeats.size}/${passengers.length})`;
    } else {
        bookButton.disabled = false;
        bookButton.textContent = `Book ${passengers.length} Seat${passengers.length > 1 ? 's' : ''} - LKR ${(passengers.length * basePricePerSeat).toLocaleString()}`;
    }
}

function updateSeatColors() {
    const availableSeats = document.querySelectorAll('.seat.available:not(.selected)');

    // Reset all available seats to neutral
    availableSeats.forEach(seat => {
        seat.classList.remove('male', 'female', 'neutral');
        seat.classList.add('neutral');
    });

    // Get unique genders of passengers who need seats
    const passengersNeedingSeats = passengers.filter(p => !p.seatId && p.gender);
    const genders = [...new Set(passengersNeedingSeats.map(p => p.gender))];

    // If we have passengers with genders, color the seats
    if (genders.length > 0) {
        // If all passengers are the same gender, use that gender
        if (genders.length === 1) {
            availableSeats.forEach(seat => {
                seat.classList.remove('neutral');
                seat.classList.add(genders[0]);
            });
        } else {
            // Mixed genders - show neutral (gray) to indicate mixed preference
            availableSeats.forEach(seat => {
                seat.classList.remove('male', 'female');
                seat.classList.add('neutral');
            });
        }
    }
}

function setupFormValidation() {
    const bookingForm = document.getElementById('booking_form');
    if (!bookingForm) return;

    // Form validation
    bookingForm.addEventListener('submit', function (e) {
        if (passengers.length === 0) {
            e.preventDefault();
            alert('Please add at least one passenger.');
            return false;
        }

        // Check if all passengers have names
        const missingNames = passengers.filter(p => !p.name || p.name.trim() === '');
        if (missingNames.length > 0) {
            e.preventDefault();
            alert('Please enter names for all passengers.');
            return false;
        }

        // Check if all passengers have genders
        const missingGenders = passengers.filter(p => !p.gender);
        if (missingGenders.length > 0) {
            e.preventDefault();
            alert('Please select gender for all passengers.');
            return false;
        }

        // Check if all passengers have seats
        const allPassengersHaveSeats = passengers.every(p => p.seatId);
        if (!allPassengersHaveSeats) {
            e.preventDefault();
            alert('Please select seats for all passengers.');
            return false;
        }

        // Show confirmation with simple seat numbers
        const totalAmount = passengers.length * basePricePerSeat;
        const seatNumbers = passengers.map(p => p.seatNumber).join(', ');
        const confirmMessage = `Confirm booking for ${passengers.length} passenger(s)?\n\nTotal Amount: LKR ${totalAmount.toLocaleString()}\n\nSeats: ${seatNumbers}`;

        if (!confirm(confirmMessage)) {
            e.preventDefault();
            return false;
        }

        // All validation passed - form can submit
        return true;
    });
}