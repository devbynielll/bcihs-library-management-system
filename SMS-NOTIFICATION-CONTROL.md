# SMS notification controls

The **SMS Logs & Settings** page shows the administrator-controlled notification permission, configured provider, and effective delivery mode.

## Permission

Only an authenticated administrator can change **Allow SMS Notifications**. Enabling requires confirmation. The server checks the role, CSRF token, saved setting version, and audit write before applying a change. Librarians may view status but cannot enable the permission.

When permission is **OFF**, the shared SMS engine blocks automatic notifications and password-recovery codes. Suppressed messages are not queued or replayed later. Library operations continue normally. When permission is **ON**, the existing mode and provider configuration still apply; this control cannot activate live delivery.

## Delivery modes and logs

- **Simulation** records the simulated result without contacting a provider or using credits.
- **Live** requires separate private provider authorization and all provider-specific safeguards. Enabling the UI permission alone cannot make it live.
- The event log displays local request outcomes. Refresh reloads local records; it does not poll provider delivery status or trigger a retry.

Provider keys, sender settings, live authorization, and one-attempt claim files stay in private configuration outside Git. Do not reset a consumed claim or resend after an uncertain provider response. The provider does not automatically fail over to another provider.

## Password recovery

The SMS recovery flow does not issue a code that cannot be delivered. If notification permission or effective delivery is unavailable, ask the authorized system administrator or library staff for identity-verified assistance. Do not bypass recovery checks or change account records directly.
