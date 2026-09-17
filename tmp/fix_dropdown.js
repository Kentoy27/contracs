// This is a temporary file to help fix the issue
// The bug is at line 5678-5684 in assets/js/app-main.js
// Line 5680 has standalone '\n' without + operator
// Need to replace:
//   + '<div class="d-flex flex-column">\n'
//   '\n'                                <-- BUG: standalone string, breaks concatenation
//   + '<span class="fw-semibold">' + name + '</span>'
// With:
//   + '<div class="d-flex flex-column">\n'
//   + '<span class="fw-semibold">' + name + '</span>'
