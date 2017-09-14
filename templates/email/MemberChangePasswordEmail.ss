<p><% _t('MemberChangePasswordEmail.HELLO', 'Hi') %> $FirstName,</p>
<p>
    <% _t('MemberChangePasswordEmail.TEXT1', 'You changed your password for', 'for a url') %> $AbsoluteBaseURL.<br />
    <% _t('MemberChangePasswordEmail.TEXT2', 'You can now use the following credentials to log in:') %>
</p>
<p>
    <% _t('MemberChangePasswordEmail.EMAIL', 'Email') %>: $Email<br />
    <% _t('MemberChangePasswordEmail.PASSWORD', 'Password') %>: $CleartextPassword
</p>
<p>
    <% _t('MemberChangePasswordEmail.REGARDS','Best regards') %>,
</p>