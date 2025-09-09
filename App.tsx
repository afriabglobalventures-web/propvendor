import React from 'react';
import { Header, Footer } from './components';
import { HomePage } from './pages';

function App() {
  return (
    <div className="flex flex-col min-h-screen bg-pv-white">
      <Header />
      <div className="flex-grow">
        <HomePage />
      </div>
      <Footer />
    </div>
  );
}

export default App;
