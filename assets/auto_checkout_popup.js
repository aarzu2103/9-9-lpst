/**
 * Auto Checkout Popup JavaScript
 * Handles the mandatory popup for auto-checkout confirmation
 */

class AutoCheckoutPopup {
    constructor() {
        this.checkInterval = null;
        this.popupShown = false;
        this.init();
    }
    
    init() {
        // Check for popup immediately on page load
        this.checkForPopup();
        
        // Check every 30 seconds for popup requirement
        this.checkInterval = setInterval(() => {
            if (!this.popupShown) {
                this.checkForPopup();
            }
        }, 30000);
    }
    
    async checkForPopup() {
        try {
            const response = await fetch('/api/auto_checkout_popup.php?action=check_popup', {
                method: 'GET',
                headers: {
                    'Cache-Control': 'no-cache'
                }
            });
            
            const data = await response.json();
            
            if (data.show_popup && !this.popupShown) {
                this.showMandatoryPopup(data);
            }
            
        } catch (error) {
            console.error('Auto-checkout popup check error:', error);
        }
    }
    
    showMandatoryPopup(data) {
        this.popupShown = true;
        
        // Stop checking since popup is now shown
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
        }
        
        // Create popup HTML
        const popupHTML = `
            <div id="autoCheckoutPopup" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: Arial, sans-serif;
            ">
                <div style="
                    background: white;
                    padding: 2rem;
                    border-radius: 12px;
                    max-width: 600px;
                    width: 90%;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
                    text-align: center;
                ">
                    <div style="
                        background: linear-gradient(45deg, #dc3545, #c82333);
                        color: white;
                        padding: 1rem;
                        border-radius: 8px;
                        margin-bottom: 1.5rem;
                        font-weight: bold;
                        font-size: 1.2rem;
                    ">
                        🕙 MANDATORY AUTO CHECKOUT REQUIRED
                    </div>
                    
                    <h3 style="color: #dc3545; margin-bottom: 1rem;">
                        Daily 10:00 AM Auto Checkout
                    </h3>
                    
                    <p style="font-size: 1.1rem; margin-bottom: 1.5rem; color: #333;">
                        <strong>${data.total_rooms} room(s)</strong> need to be checked out for <strong>${data.checkout_date}</strong>
                        <br>Checkout time will be set to <strong>10:00 AM</strong>
                    </p>
                    
                    <div style="
                        background: #f8f9fa;
                        border: 1px solid #dee2e6;
                        border-radius: 8px;
                        padding: 1rem;
                        margin: 1rem 0;
                        max-height: 200px;
                        overflow-y: auto;
                    ">
                        <h4 style="margin-top: 0; color: #495057;">Rooms to be checked out:</h4>
                        <ul style="text-align: left; margin: 0; padding-left: 1.5rem;">
                            ${data.pending_rooms.map(room => 
                                `<li><strong>${room.resource_name}</strong>: ${room.client_name} (${room.client_mobile})</li>`
                            ).join('')}
                        </ul>
                    </div>
                    
                    <div style="
                        background: #fff3cd;
                        border: 1px solid #ffeaa7;
                        border-radius: 8px;
                        padding: 1rem;
                        margin: 1rem 0;
                        color: #856404;
                    ">
                        <strong>⚠️ Important:</strong> This action cannot be undone. All listed rooms will be marked as checked out with checkout time set to 10:00 AM.
                    </div>
                    
                    <div style="margin-top: 2rem;">
                        <button id="confirmAutoCheckout" style="
                            background: #dc3545;
                            color: white;
                            border: none;
                            padding: 1rem 2rem;
                            font-size: 1.1rem;
                            font-weight: bold;
                            border-radius: 8px;
                            cursor: pointer;
                            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
                            transition: all 0.3s ease;
                        " onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
                            ✅ CONFIRM AUTO CHECKOUT
                        </button>
                    </div>
                    
                    <div id="processingMessage" style="
                        display: none;
                        margin-top: 1rem;
                        padding: 1rem;
                        background: #d1ecf1;
                        border-radius: 8px;
                        color: #0c5460;
                    ">
                        <div style="display: flex; align-items: center; justify-content: center;">
                            <div style="
                                border: 3px solid #f3f3f3;
                                border-top: 3px solid #007bff;
                                border-radius: 50%;
                                width: 20px;
                                height: 20px;
                                animation: spin 1s linear infinite;
                                margin-right: 10px;
                            "></div>
                            Processing auto checkout...
                        </div>
                    </div>
                </div>
            </div>
            
            <style>
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        `;
        
        // Add popup to page
        document.body.insertAdjacentHTML('beforeend', popupHTML);
        
        // Add event listener for confirm button
        document.getElementById('confirmAutoCheckout').addEventListener('click', () => {
            this.processAutoCheckout(data.checkout_date);
        });
        
        // Mark popup as shown
        this.markPopupShown(data.checkout_date);
        
        // Prevent page interaction
        document.body.style.overflow = 'hidden';
    }
    
    async processAutoCheckout(checkoutDate) {
        const confirmButton = document.getElementById('confirmAutoCheckout');
        const processingMessage = document.getElementById('processingMessage');
        
        // Show processing state
        confirmButton.style.display = 'none';
        processingMessage.style.display = 'block';
        
        try {
            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                             document.querySelector('input[name="csrf_token"]')?.value || '';
            
            const response = await fetch('/api/auto_checkout_popup.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'process_checkout',
                    checkout_date: checkoutDate,
                    csrf_token: csrfToken
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Show success message
                processingMessage.innerHTML = `
                    <div style="color: #155724; background: #d4edda; padding: 1rem; border-radius: 8px;">
                        <h4 style="margin: 0 0 0.5rem 0;">✅ Auto Checkout Completed Successfully!</h4>
                        <p style="margin: 0;">
                            Processed: ${result.rooms_processed} rooms<br>
                            Successful: ${result.successful}<br>
                            Failed: ${result.failed}<br>
                            All checkout times set to 10:00 AM
                        </p>
                    </div>
                `;
                
                // Auto-close popup after 3 seconds and reload page
                setTimeout(() => {
                    this.closePopup();
                    window.location.reload();
                }, 3000);
                
            } else {
                // Show error message
                processingMessage.innerHTML = `
                    <div style="color: #721c24; background: #f8d7da; padding: 1rem; border-radius: 8px;">
                        <h4 style="margin: 0 0 0.5rem 0;">❌ Auto Checkout Failed</h4>
                        <p style="margin: 0;">${result.message || 'Unknown error occurred'}</p>
                        <button onclick="window.location.reload()" style="
                            background: #007bff;
                            color: white;
                            border: none;
                            padding: 0.5rem 1rem;
                            border-radius: 4px;
                            margin-top: 1rem;
                            cursor: pointer;
                        ">Reload Page</button>
                    </div>
                `;
            }
            
        } catch (error) {
            console.error('Auto checkout processing error:', error);
            processingMessage.innerHTML = `
                <div style="color: #721c24; background: #f8d7da; padding: 1rem; border-radius: 8px;">
                    <h4 style="margin: 0 0 0.5rem 0;">❌ Network Error</h4>
                    <p style="margin: 0;">Failed to process auto checkout. Please try again.</p>
                    <button onclick="window.location.reload()" style="
                        background: #007bff;
                        color: white;
                        border: none;
                        padding: 0.5rem 1rem;
                        border-radius: 4px;
                        margin-top: 1rem;
                        cursor: pointer;
                    ">Reload Page</button>
                </div>
            `;
        }
    }
    
    async markPopupShown(date) {
        try {
            await fetch('/api/auto_checkout_popup.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'mark_popup_shown',
                    date: date
                })
            });
        } catch (error) {
            console.error('Failed to mark popup shown:', error);
        }
    }
    
    closePopup() {
        const popup = document.getElementById('autoCheckoutPopup');
        if (popup) {
            popup.remove();
            document.body.style.overflow = '';
        }
        this.popupShown = false;
    }
}

// Initialize auto checkout popup system when page loads
if (typeof window !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        // Only initialize for admin users
        if (document.body.dataset.userRole === 'ADMIN') {
            new AutoCheckoutPopup();
        }
    });
}