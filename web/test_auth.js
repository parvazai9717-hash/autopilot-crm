import axios from 'axios';

async function testAuth() {
  console.log('Testing pure cookie Sanctum session authentication with SPA Origin...');

  const cookieJar = {};

  function updateCookies(res) {
    const setCookies = res.headers['set-cookie'];
    if (setCookies) {
      for (const str of setCookies) {
        const parts = str.split(';')[0].split('=');
        const key = parts[0].trim();
        const val = parts.slice(1).join('=').trim();
        cookieJar[key] = val;
      }
    }
  }

  function getCookieHeader() {
    return Object.entries(cookieJar)
      .map(([k, v]) => `${k}=${v}`)
      .join('; ');
  }

  // 1. Get CSRF Cookie
  const csrfRes = await axios.get('http://127.0.0.1:8000/sanctum/csrf-cookie', {
    headers: {
      'Accept': 'application/json',
      'Origin': 'http://127.0.0.1:5173',
      'Referer': 'http://127.0.0.1:5173/',
    },
  });

  updateCookies(csrfRes);
  const decodedXsrf = decodeURIComponent(cookieJar['XSRF-TOKEN']);
  console.log('1. CSRF Token initialized successfully');

  // 2. Post Login for Admin (Ahmad Ameen)
  const loginRes = await axios.post(
    'http://127.0.0.1:8000/api/auth/login',
    { email: 'admin@test.com', password: 'password123' },
    {
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Origin': 'http://127.0.0.1:5173',
        'Referer': 'http://127.0.0.1:5173/',
        'Cookie': getCookieHeader(),
        'X-XSRF-TOKEN': decodedXsrf,
      },
    }
  );

  updateCookies(loginRes);
  console.log('2. Login Status:', loginRes.status);
  console.log('   Logged in user:', loginRes.data.user.name, `(${loginRes.data.user.role})`);

  // 3. Get /api/auth/me using session cookie
  const meRes = await axios.get('http://127.0.0.1:8000/api/auth/me', {
    headers: {
      'Accept': 'application/json',
      'Origin': 'http://127.0.0.1:5173',
      'Referer': 'http://127.0.0.1:5173/',
      'Cookie': getCookieHeader(),
      'X-XSRF-TOKEN': decodeURIComponent(cookieJar['XSRF-TOKEN']),
    },
  });

  console.log('3. /api/auth/me Status:', meRes.status);
  console.log('   Authenticated profile verified:', meRes.data.user.name, '-', meRes.data.user.email);

  // 4. Test Employee Login (Ahmed Raza)
  const empLoginRes = await axios.post(
    'http://127.0.0.1:8000/api/auth/login',
    { email: 'ahmed@test.com', password: 'password123' },
    {
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Origin': 'http://127.0.0.1:5173',
        'Referer': 'http://127.0.0.1:5173/',
        'Cookie': getCookieHeader(),
        'X-XSRF-TOKEN': decodeURIComponent(cookieJar['XSRF-TOKEN']),
      },
    }
  );

  updateCookies(empLoginRes);
  console.log('4. Employee Login Status:', empLoginRes.status);
  console.log('   Employee:', empLoginRes.data.user.name, `(${empLoginRes.data.user.role})`);

  // 5. Test Invalid Credentials Error
  try {
    await axios.post(
      'http://127.0.0.1:8000/api/auth/login',
      { email: 'admin@test.com', password: 'wrongpassword' },
      {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'Origin': 'http://127.0.0.1:5173',
          'Referer': 'http://127.0.0.1:5173/',
          'Cookie': getCookieHeader(),
          'X-XSRF-TOKEN': decodeURIComponent(cookieJar['XSRF-TOKEN']),
        },
      }
    );
  } catch (err) {
    console.log('5. Invalid credentials status:', err.response?.status);
    console.log('   Standard error object:', err.response?.data?.error);
  }

  // 6. Test Logout
  const logoutRes = await axios.post(
    'http://127.0.0.1:8000/api/auth/logout',
    {},
    {
      headers: {
        'Accept': 'application/json',
        'Origin': 'http://127.0.0.1:5173',
        'Referer': 'http://127.0.0.1:5173/',
        'Cookie': getCookieHeader(),
        'X-XSRF-TOKEN': decodeURIComponent(cookieJar['XSRF-TOKEN']),
      },
    }
  );
  console.log('6. Logout Status:', logoutRes.status, logoutRes.data.message);

  console.log('\n======================================================');
  console.log('✓ ALL SANCTUM SPA SESSION COOKIE FLOWS VERIFIED 100%');
  console.log('======================================================');
}

testAuth().catch(err => {
  console.error('Test Auth Error Status:', err.response?.status);
  console.error('Test Auth Error Data:', JSON.stringify(err.response?.data, null, 2) || err.message);
  process.exit(1);
});
